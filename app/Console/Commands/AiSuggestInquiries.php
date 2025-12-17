<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use App\Models\AiInquiry;
use App\Models\Inquiry;
use App\Services\Ai\SuggestionService;

class AiSuggestInquiries extends Command
{
    protected $signature = 'ai:suggest {--limit=50} {--force : Rebuild suggestions even if they exist}';
    protected $description = 'Generate hotel suggestions and store them on ai_inquiries.suggestions_payload';

    public function handle(SuggestionService $sugg): int
    {
        $limit = (int) $this->option('limit');
        $force = (bool) $this->option('force');
        $statuses = ['parsed', 'needs_info', 'error'];

        // ako je --force, dozvoli da ponovo gradi i one koji su ranije završili kao no_availability
        if ($force) {
            $statuses[] = 'no_availability';
        }

        $items = AiInquiry::query()
            ->whereNotNull('inquiry_id')
            ->where('ai_stopped', false)
            ->whereIn('status', $statuses)
            ->orderBy('received_at')
            ->limit($limit)
            ->get();

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($items as $ai) {
            try {
                DB::transaction(function () use ($ai, $sugg, $force, &$processed, &$skipped) {
                    $inquiry = Inquiry::find($ai->inquiry_id);

                    if (! $inquiry) {
                        $ai->status = 'error';
                        $ai->missing_fields = ['inquiry_not_found'];
                        $ai->save();
                        $skipped++;
                        return;
                    }

                    // Ako je već posle odgovora, ne diramo
                    if (in_array($inquiry->status, ['replied', 'closed'], true)) {
                        $skipped++;
                        return;
                    }

                    // Ako već ima sačuvane sugestije i nije force
                    if (! $force && ! empty($ai->suggestions_payload)) {
                        if ($inquiry->status === 'extracted') {
                            $inquiry->status = 'suggested';
                            $inquiry->save();
                        }

                        $ai->status = 'suggested';
                        $ai->suggested_at = Carbon::now();
                        $ai->save();

                        $skipped++;
                        return;
                    }

                    $parsed = $this->buildParsedFromInquiry($inquiry);

                    // ✅ NEW: primary + alternatives + log
                    $out = $sugg->getWithAlternatives($parsed);

                    $primary = $out['primary'] ?? [];
                    $alts    = $out['alternatives'] ?? [];
                    $log     = $out['log'] ?? [];

                    // payload: uvek čuvamo top 5 primary i top 5 alternatives + log
                    $ai->suggestions_payload = [
                        'primary'      => array_slice($primary, 0, 5),
                        'alternatives' => array_slice($alts, 0, 5),
                        'log'          => $log,
                    ];
                    $ai->suggested_at = Carbon::now();

                    // Business: ako imamo bilo šta (primary ili alt) -> suggested
                    $hasAny = (count($primary) > 0) || (count($alts) > 0);

                    if ($hasAny) {
                        $inquiry->status = 'suggested';
                        $inquiry->processed_at = Carbon::now();
                        $inquiry->save();

                        $ai->status = 'suggested';
                    } else {
                        // Nema ni primary ni alternative po standardima
                        if ($inquiry->status === 'new') {
                            $inquiry->status = 'extracted';
                            $inquiry->save();
                        }

                        $ai->status = 'no_availability';
                    }

                    // očisti error/missing ako je ranije bio error
                    if ($ai->status !== 'error') {
                        $ai->missing_fields = null;
                    }

                    $ai->save();
                    $processed++;
                });
            } catch (\Throwable $e) {
                $failed++;

                $this->error("Suggest failed for ai_inquiry_id={$ai->id}: {$e->getMessage()}");

                try {
                    $ai->status = 'error';
                    $ai->missing_fields = array_values(array_unique(array_filter([
                        'suggest_exception',
                        mb_strimwidth((string) $e->getMessage(), 0, 160, '...'),
                    ])));
                    $ai->save();
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        $this->info("Done. Suggested: {$processed}, Skipped: {$skipped}, Failed: {$failed}");
        return self::SUCCESS;
    }

    private function buildParsedFromInquiry(Inquiry $inquiry): array
    {
        $childrenCount = (int) ($inquiry->children ?? 0);

        $children = [];
        $ages = [];

        if (! empty($inquiry->children_ages)) {
            $decoded = json_decode($inquiry->children_ages, true);
            if (is_array($decoded)) {
                $ages = array_values($decoded);
            }
        }

        if ($childrenCount > 0) {
            for ($i = 0; $i < $childrenCount; $i++) {
                $children[] = ['age' => $ages[$i] ?? null];
            }
        }

        return [
            'region'           => $inquiry->region,
            'location'         => $inquiry->location,
            'adults'           => $inquiry->adults ?? 2,
            'children'         => $children,
            'budget_per_night' => $inquiry->budget_max ? (float) $inquiry->budget_max : null,
        ];
    }
}
