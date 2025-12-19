<?php

namespace App\Console\Commands;

use App\Models\AiInquiry;
use App\Models\Inquiry;
use App\Services\InquiryAiExtractor;
use App\Services\InquiryMissingData;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AiParseInquiries extends Command
{
    protected $signature = 'ai:parse
        {--limit=50}
        {--retry : Uključi i ai_inquiries sa status=needs_info ili error}
        {--force : Prepiši postojeća polja u Inquiry (inače popunjava samo prazna)}';

    protected $description = 'Parse ai_inquiries -> fill Inquiry fields (uses fallback when AI_ENABLED=false).';

    public function handle(InquiryAiExtractor $extractor): int
    {
        $limit = (int) $this->option('limit');
        $retry = (bool) $this->option('retry');
        $force = (bool) $this->option('force');

        // IDs first -> lock per row in TX (avoid race)
        $q = AiInquiry::query()
            ->whereNotNull('inquiry_id')
            ->where('ai_stopped', false)
            ->orderBy('received_at');

        if ($retry) {
            $q->whereIn('status', ['synced', 'needs_info', 'error']);
        } else {
            $q->where('status', 'synced');
        }

        $ids = $q->limit($limit)->pluck('id')->all();

        $processed = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, $extractor, $force, &$processed, &$skipped) {

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
                        $ai->parsed_at = Carbon::now();
                        $ai->save();
                        $skipped++;
                        return;
                    }

                    // Ako je već u kasnijoj fazi, ne diramo
                    if (in_array($inquiry->status, ['suggested', 'replied', 'closed'], true)) {
                        $ai->status = 'parsed';
                        $ai->missing_fields = null;
                        $ai->parsed_at = Carbon::now();
                        $ai->save();
                        $skipped++;
                        return;
                    }

                    $out = $extractor->extract($inquiry); // standard array + _mode

                    $this->fillFromExtractor($inquiry, $out, $force);

                    $missingHuman = InquiryMissingData::detect($inquiry);

                    $inquiry->extraction_mode  = (string) ($out['_mode'] ?? 'fallback');
                    $inquiry->extraction_debug = $out; // cast array u modelu
                    $inquiry->processed_at     = Carbon::now();

                    $inquiry->status = empty($missingHuman)
                        ? Inquiry::STATUS_EXTRACTED
                        : Inquiry::STATUS_NEEDS_INFO;

                    $inquiry->save();

                    $ai->status = empty($missingHuman) ? 'parsed' : 'needs_info';
                    $ai->missing_fields = empty($missingHuman) ? null : $missingHuman;
                    $ai->parsed_at = Carbon::now();
                    $ai->save();

                    $processed++;
                });
            } catch (\Throwable $e) {
                $failed++;

                try {
                    AiInquiry::query()->whereKey($id)->update([
                        'status' => 'error',
                        'missing_fields' => array_values(array_unique(array_filter([
                            'exception',
                            mb_strimwidth((string) $e->getMessage(), 0, 160, '...'),
                        ]))),
                        'parsed_at' => Carbon::now(),
                    ]);
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        $this->info("Done. Parsed: {$processed}, Skipped: {$skipped}, Failed: {$failed}");
        return self::SUCCESS;
    }

    private function fillFromExtractor(Inquiry $inquiry, array $out, bool $force): void
    {
        $set = function (string $field, $value) use ($inquiry, $force) {
            if ($value === null || $value === '') {
                return;
            }
            if ($force || $inquiry->{$field} === null || $inquiry->{$field} === '') {
                $inquiry->{$field} = $value;
            }
        };

        $set('region', $out['region'] ?? null);
        $set('location', $out['location'] ?? null);
        $set('month_hint', $out['month_hint'] ?? null);

        $set('date_from', $out['date_from'] ?? null);
        $set('date_to', $out['date_to'] ?? null);
        $set('nights', $out['nights'] ?? null);

        // Normalize adults/children (AI može vratiti string/array)
        $adults = $out['adults'] ?? null;
        $children = $out['children'] ?? null;

        if (is_array($adults))   $adults = count($adults);
        if (is_array($children)) $children = count($children);

        if (is_string($adults)) {
            $n = (int) preg_replace('/\D+/', '', $adults);
            $adults = $n > 0 ? $n : null;
        }
        if (is_string($children)) {
            $n = (int) preg_replace('/\D+/', '', $children);
            $children = $n >= 0 ? $n : null;
        }

        $set('adults', $adults);
        $set('children', $children);

        // children_ages: može doći kao string ili array -> normalize
        if (array_key_exists('children_ages', $out)) {
            $ages = \App\Services\InquiryMissingData::normalizeChildrenAges($out['children_ages']);
            if ($force || empty($inquiry->children_ages)) {
                $inquiry->children_ages = $ages;
            }
        }

        $set('budget_min', $out['budget_min'] ?? null);
        $set('budget_max', $out['budget_max'] ?? null);

        $set('wants_near_beach', $out['wants_near_beach'] ?? null);
        $set('wants_parking', $out['wants_parking'] ?? null);
        $set('wants_quiet', $out['wants_quiet'] ?? null);
        $set('wants_pets', $out['wants_pets'] ?? null);
        $set('wants_pool', $out['wants_pool'] ?? null);

        $set('special_requirements', $out['special_requirements'] ?? null);
        $set('language', $out['language'] ?? null);
    }
}
