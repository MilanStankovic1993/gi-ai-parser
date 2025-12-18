<?php

namespace App\Console\Commands;

use App\Jobs\ProcessPriceList;
use App\Models\PriceList;
use Illuminate\Console\Command;

class ProcessPriceListCommand extends Command
{
    /**
     * Primer:
     * php artisan pricelists:process 1
     */
    protected $signature = 'pricelists:process {priceListId : ID cenovnika}';
    protected $description = 'Pokreće obradu jednog cenovnika preko queue job-a.';

    public function handle(): int
    {
        $id = (int) $this->argument('priceListId');

        /** @var PriceList|null $priceList */
        $priceList = PriceList::query()->find($id);

        if (! $priceList) {
            $this->error("PriceList sa ID {$id} ne postoji.");
            return self::FAILURE;
        }

        $filename = (string) ($priceList->original_filename ?? 'unknown');
        $this->info("Pokrećem obradu za PriceList #{$id} ({$filename})...");

        ProcessPriceList::dispatch($priceList->id);

        $this->info('Job dispatchovan (ili odrađen sync, zavisi od QUEUE_CONNECTION).');

        return self::SUCCESS;
    }
}
