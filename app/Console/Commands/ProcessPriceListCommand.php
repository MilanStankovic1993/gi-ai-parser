<?php

namespace App\Console\Commands;

use App\Jobs\ProcessPriceList;
use App\Models\PriceList;
use Illuminate\Console\Command;

class ProcessPriceListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Primer poziva:
     * php artisan pricelists:process 1
     */
    protected $signature = 'pricelists:process {priceListId}';

    /**
     * The console command description.
     */
    protected $description = 'Pokreće obradu jednog cenovnika preko queue job-a.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $id = (int) $this->argument('priceListId');

        $priceList = PriceList::find($id);

        if (! $priceList) {
            $this->error("Price list sa ID {$id} ne postoji.");
            return self::FAILURE;
        }

        $this->info("Pokrećem obradu za PriceList #{$id} ({$priceList->original_filename})...");

        // za sada sync queue (po defaultu), kasnije ćemo prebaciti na redis
        ProcessPriceList::dispatch($priceList->id);

        $this->info('Job dispatchovan (ili odrađen sync, zavisi od QUEUE_CONNECTION).');

        return self::SUCCESS;
    }
}
