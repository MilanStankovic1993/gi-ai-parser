<?php

namespace App\Jobs;

use App\Models\PriceList;
use App\Models\PriceListLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use App\Models\PriceListRow;
use App\Services\FakePriceListParser;
use App\Services\PriceListParsingService;
use App\Services\PriceListValidator;

class ProcessPriceList implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $priceListId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $priceListId)
    {
        $this->priceListId = $priceListId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $priceList = PriceList::find($this->priceListId);

        if (! $priceList) {
            return;
        }

        // status → processing
        $priceList->update(['status' => 'processing']);

        PriceListLog::create([
            'price_list_id' => $priceList->id,
            'step'          => 'start',
            'raw_input'     => 'Processing started for price list ID '.$priceList->id,
            'raw_output'    => null,
        ]);

        try {
            $parsingService = app(PriceListParsingService::class);
            $validator      = app(PriceListValidator::class);

            // 1) PARSIRANJE
            $rows = $parsingService->parse($priceList);

            // 2) VALIDACIJA (pre nego što upišemo u bazu)
            $issues = $validator->validate($rows);

            // 3) obriši stare redove
            $priceList->rows()->delete();

            foreach ($rows as $row) {
                $priceList->rows()->create([
                    'sezona_od'     => $row['sezona_od'] ?? null,
                    'sezona_do'     => $row['sezona_do'] ?? null,
                    'tip_jedinice'  => $row['tip_jedinice'] ?? null,
                    'cena_noc'      => $row['cena_noc'] ?? null,
                    'min_noci'      => $row['min_noci'] ?? null,
                    'doplate'       => $row['doplate'] ?? null,
                    'promo'         => $row['promo'] ?? null,
                    'napomena'      => $row['napomena'] ?? null,
                ]);
            }
            PriceListLog::create([
    'price_list_id' => $priceList->id,
    'step'          => 'rows_inserted',
    'raw_input'     => null,
    'raw_output'    => 'Inserted rows count: ' . count($rows),
]);

            // 5) log parsiranog outputa
            PriceListLog::create([
                'price_list_id' => $priceList->id,
                'step'          => 'parsed',
                'raw_input'     => null,
                'raw_output'    => json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            ]);

            // 6) ako ima issue-a → needs_review, inače processed
            if (! empty($issues)) {
                $priceList->update([
                    'status'       => 'needs_review',
                    'processed_at' => now(),
                ]);

                PriceListLog::create([
                    'price_list_id' => $priceList->id,
                    'step'          => 'validation_failed',
                    'raw_input'     => null,
                    'raw_output'    => json_encode($issues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                ]);
            } else {
                $priceList->update([
                    'status'       => 'processed',
                    'processed_at' => now(),
                ]);

                PriceListLog::create([
                    'price_list_id' => $priceList->id,
                    'step'          => 'finished',
                    'raw_input'     => null,
                    'raw_output'    => 'Processing finished successfully (no validation issues).',
                ]);
            }
        } catch (Throwable $e) {

            $priceList->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            PriceListLog::create([
                'price_list_id' => $priceList->id,
                'step'          => 'error',
                'raw_input'     => null,
                'raw_output'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

}
