<?php

namespace App\Console\Commands;

use App\Models\AiInquiry;
use App\Models\Inquiry;
use App\Services\InquiryAccommodationMatcher;
use App\Services\InquiryMissingData;
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

        // U normalnom toku: sugestije imaju smisla tek kad je parsed.
        // needs_info НЕ СМЕ да прави понуду (1:1 dogovor) – ali smemo da ga "dotaknemo" radi statusa.
        $statuses = ['parsed', 'needs_info'];

        // error obrađuj samo kad force (da ne trošimo vreme i da ne maskiramo probleme)
        if ($force) {
            $statuses[] = 'error';
            $statuses[] = 'no_availability';
            $statuses[] = 'suggested';
        }

        $ids = AiInquiry::query()
            ->whereNotNull('inquiry_id')
            ->where('ai_stopped', false)
            ->whereIn('status', $statuses)
            ->orderBy('received_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $processed = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, $matcher, $force, &$processed, &$skipped) {

                    /** @var AiInquiry|null $ai */
                    $ai = AiInquiry::query()->whereKey($id)->lockForUpdate()->first();

                    if (! $ai) {
                        $skipped++;
                        return;
                    }

                    if ($ai->ai_stopped) {
                        $skipped++;
                        return;
                    }

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

                    // 1:1 zahtev — ako fale ključni podaci, NEMA ponude/sugestija
                    $missing = InquiryMissingData::detect($inquiry);
                    if (! empty($missing)) {
                        // držimo jasno stanje
                        if ($inquiry->status !== Inquiry::STATUS_NEEDS_INFO) {
                            $inquiry->status = Inquiry::STATUS_NEEDS_INFO;
                            $inquiry->processed_at = Carbon::now();
                            $inquiry->save();
                        }

                        $ai->status = 'needs_info';
                        $ai->missing_fields = $missing;
                        $ai->suggested_at = Carbon::now();
                        $ai->save();

                        $skipped++;
                        return;
                    }

                    // Ako već ima sačuvane sugestije i nije force, samo osveži statuse
                    if (! $force && ! empty($ai->suggestions_payload)) {
                        if (in_array($inquiry->status, ['new', 'extracted'], true)) {
                            $inquiry->status = 'suggested';
                            $inquiry->processed_at = Carbon::now();
                            $inquiry->save();
                        }

                        $ai->status = 'suggested';
                        $ai->missing_fields = null;
                        $ai->suggested_at = Carbon::now();
                        $ai->save();

                        $skipped++;
                        return;
                    }

                    // ✅ SOURCE OF TRUTH: matcher radi primary + alternatives + log
                    $out = $matcher->matchWithAlternatives($inquiry, 5, 5);

                    $primary = collect($out['primary'] ?? [])->values()->all();
                    $alts    = collect($out['alternatives'] ?? [])->values()->all();
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
                        if ($inquiry->status === 'new') {
                            $inquiry->status = 'extracted';
                            $inquiry->processed_at = Carbon::now();
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

                $this->error("Suggest failed for ai_inquiry_id={$id}: {$e->getMessage()}");

                try {
                    AiInquiry::query()->whereKey($id)->update([
                        'status' => 'error',
                        'missing_fields' => array_values(array_unique(array_filter([
                            'suggest_exception',
                            mb_strimwidth((string) $e->getMessage(), 0, 160, '...'),
                        ]))),
                    ]);
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        $this->info("Done. Suggested: {$processed}, Skipped: {$skipped}, Failed: {$failed}");
        return self::SUCCESS;
    }
}
