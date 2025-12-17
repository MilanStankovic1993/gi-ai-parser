<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use App\Models\AiInquiry;
use App\Models\Inquiry;
use App\Services\Ai\AiInquiryParser;

class AiParseInquiries extends Command
{
    protected $signature = 'ai:parse
        {--limit=50}
        {--retry : Uključi i ai_inquiries sa status=needs_info ili error}
        {--force : Prepiši postojeća polja u Inquiry (inače popunjava samo prazna)}';

    protected $description = 'Parse ai_inquiries -> fill Inquiry fields (uses fallback when AI_ENABLED=false).';

    public function handle(AiInquiryParser $parser): int
    {
        $limit = (int) $this->option('limit');
        $retry = (bool) $this->option('retry');
        $force = (bool) $this->option('force');

        $q = AiInquiry::query()
            ->whereNotNull('inquiry_id')
            ->where('ai_stopped', false)
            ->orderBy('received_at');

        if ($retry) {
            $q->whereIn('status', ['synced', 'needs_info', 'error']);
        } else {
            $q->where('status', 'synced');
        }

        $items = $q->limit($limit)->get();

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($items as $ai) {
            try {
                DB::transaction(function () use ($ai, $parser, $force, &$processed, &$skipped) {
                    $inquiry = Inquiry::find($ai->inquiry_id);

                    if (! $inquiry) {
                        $ai->status = 'error';
                        $ai->missing_fields = ['inquiry_not_found'];
                        $ai->parsed_at = Carbon::now();
                        $ai->save();
                        $skipped++;
                        return;
                    }

                    // Ako je Inquiry već u kasnijoj fazi (suggested/replied/closed), ne diramo ga.
                    if (in_array($inquiry->status, ['suggested', 'replied', 'closed'], true)) {
                        $ai->status = 'parsed';
                        $ai->missing_fields = null;
                        $ai->parsed_at = Carbon::now();
                        $ai->save();
                        $skipped++;
                        return;
                    }

                    $parsed = $parser->parse((string) $inquiry->raw_message);

                    // Popunjavaj samo prazna polja (osim ako je --force)
                    $this->fill($inquiry, $parsed, $force);

                    $missing = $this->computeMissing($inquiry);

                    $inquiry->extraction_mode  = filter_var(env('AI_ENABLED', true), FILTER_VALIDATE_BOOL) ? 'ai' : 'fallback';
                    $inquiry->extraction_debug = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $inquiry->processed_at     = Carbon::now();

                    // Status ENUM: ne “downgrade”-uj extracted nazad na new
                    if (empty($missing)) {
                        $inquiry->status = 'extracted';
                    } else {
                        if ($inquiry->status !== 'extracted') {
                            $inquiry->status = 'new';
                        }
                    }

                    $inquiry->save();

                    // Pipeline status (free string)
                    $ai->status = empty($missing) ? 'parsed' : 'needs_info';
                    $ai->parsed_at = Carbon::now();
                    $ai->missing_fields = $missing ?: null;
                    $ai->save();

                    $processed++;
                });
            } catch (\Throwable $e) {
                $failed++;

                // Ne rušimo celu komandu; beležimo u ai_inquiries
                try {
                    $ai->status = 'error';
                    $ai->missing_fields = array_values(array_unique(array_filter([
                        'exception',
                        mb_strimwidth((string) $e->getMessage(), 0, 160, '...'),
                    ])));
                    $ai->parsed_at = Carbon::now();
                    $ai->save();
                } catch (\Throwable) {
                    // ignore secondary failure
                }
            }
        }

        $this->info("Done. Parsed: {$processed}, Skipped: {$skipped}, Failed: {$failed}");
        return self::SUCCESS;
    }

    private function fill(Inquiry $inquiry, array $parsed, bool $force): void
    {
        $setIfEmpty = function (string $field, $value) use ($inquiry, $force) {
            if ($value === null || $value === '') return;
            if ($force || empty($inquiry->{$field})) {
                $inquiry->{$field} = $value;
            }
        };

        $setIfEmpty('region',   $parsed['region']   ?? null);
        $setIfEmpty('location', $parsed['location'] ?? null);

        // datumi
        if (!empty($parsed['check_in'])) {
            $setIfEmpty('date_from', $parsed['check_in']); // YYYY-MM-DD
        }
        $setIfEmpty('month_hint', $parsed['month_hint'] ?? null);

        $setIfEmpty('nights',  $parsed['nights'] ?? null);
        $setIfEmpty('adults',  $parsed['adults'] ?? null);

        // children: array<['age'=>..]>
        $childrenArr = $parsed['children'] ?? null;
        if (is_array($childrenArr)) {
            if ($force || $inquiry->children === null) {
                $inquiry->children = count($childrenArr);
            }

            $ages = [];
            foreach ($childrenArr as $c) {
                $age = $c['age'] ?? null;
                if (is_numeric($age)) $ages[] = (int) $age;
            }
            $ages = array_values(array_unique($ages));

            if (!empty($ages)) {
                // kolona je string, snimamo JSON string
                if ($force || empty($inquiry->children_ages)) {
                    $inquiry->children_ages = json_encode($ages, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        // budžet (int)
        if (isset($parsed['budget_per_night']) && is_numeric($parsed['budget_per_night'])) {
            $budget = (int) $parsed['budget_per_night'];
            if ($budget > 0) {
                $setIfEmpty('budget_max', $budget);
            }
        }

        // wants[]
        $wants = $parsed['wants'] ?? null;
        if (is_array($wants)) {
            $map = [
                'close_to_beach' => 'wants_near_beach',
                'parking'        => 'wants_parking',
                'quiet'          => 'wants_quiet',
                'pool'           => 'wants_pool',
                'pets_allowed'   => 'wants_pets',
            ];

            foreach ($map as $wantKey => $field) {
                if (in_array($wantKey, $wants, true)) {
                    if ($force || $inquiry->{$field} === null) {
                        $inquiry->{$field} = 1;
                    }
                }
            }
        }

        $setIfEmpty('language', $parsed['language'] ?? null);
    }

    private function computeMissing(Inquiry $inquiry): array
    {
        $missing = [];

        $hasPeople = (int) ($inquiry->adults ?? 0) > 0 || (int) ($inquiry->children ?? 0) > 0;
        if (! $hasPeople) $missing[] = 'people';

        $hasDates = !empty($inquiry->date_from) || !empty($inquiry->date_to) || !empty($inquiry->month_hint);
        if (! $hasDates) $missing[] = 'dates';

        if ((int) ($inquiry->children ?? 0) > 0) {
            $ages = $inquiry->children_ages;
            if (empty($ages) || (is_string($ages) && trim($ages) === '')) {
                $missing[] = 'children_ages';
            }
        }

        return $missing;
    }
}
