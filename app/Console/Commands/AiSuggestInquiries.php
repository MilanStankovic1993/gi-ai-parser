<?php

namespace App\Console\Commands;

use App\Models\AiInquiry;
use App\Models\Inquiry;
use App\Services\InquiryAccommodationMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AiSuggestInquiries extends Command
{
    protected $signature = 'ai:suggest {--limit=50} {--force : Rebuild suggestions even if they exist}';
    protected $description = 'Generate hotel suggestions and store them on ai_inquiries.suggestions_payload';

    public function handle(InquiryAccommodationMatcher $matcher): int
    {
        $limit = (int) $this->option('limit');
        $force = (bool) $this->option('force');

        $statuses = ['parsed', 'needs_info', 'error'];
        if ($force) {
            $statuses[] = 'no_availability';
            $statuses[] = 'suggested';
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
                DB::transaction(function () use ($ai, $matcher, $force, &$processed, &$skipped) {

                    /** @var Inquiry|null $inquiry */
                    $inquiry = Inquiry::query()->find($ai->inquiry_id);

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

                    // ✅ SOURCE OF TRUTH: matcher radi primary + alternatives + log
                    $out = $matcher->matchWithAlternatives($inquiry, 5, 5);

                    $primary = ($out['primary'] ?? collect())->values()->all();
                    $alts    = ($out['alternatives'] ?? collect())->values()->all();
                    $log     = $out['log'] ?? [];

                    $ai->suggestions_payload = [
                        'primary'      => array_slice($primary, 0, 5),
                        'alternatives' => array_slice($alts, 0, 5),
                        'log'          => $log,
                    ];
                    $ai->suggested_at = Carbon::now();

                    $hasAny = (count($primary) > 0) || (count($alts) > 0);

                    if ($hasAny) {
                        $inquiry->status = 'suggested';
                        $inquiry->processed_at = Carbon::now();
                        $inquiry->save();

                        $ai->status = 'suggested';
                        $ai->missing_fields = null;
                    } else {
                        // nema ni primary ni alternative
                        if ($inquiry->status === 'new') {
                            $inquiry->status = 'extracted';
                            $inquiry->save();
                        }

                        $ai->status = 'no_availability';
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
}
