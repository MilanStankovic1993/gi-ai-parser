<?php

namespace App\Services;

use App\Models\PriceList;
use App\Services\Contracts\PriceListParserInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader as XLSXReader;

class ExcelPriceListParser implements PriceListParserInterface
{
    public function parse(PriceList $priceList): array
    {
        $relativePath = $priceList->original_path;

        // isti disk kao FileUpload (disk('public'))
        $disk = Storage::disk('public');

        if (! $disk->exists($relativePath)) {
            return [];
        }

        $filePath = $disk->path($relativePath);

        $reader = new XLSXReader();
        $reader->open($filePath);

        $rows = [];
        $isHeaderRow = true;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = [];

                foreach ($row->getCells() as $cell) {
                    $cells[] = $cell->getValue();
                }

                // preskoči totalno prazne redove
                if (empty(array_filter($cells, fn ($v) => $v !== null && $v !== ''))) {
                    continue;
                }

                // prvi ne-prazan red = header
                if ($isHeaderRow) {
                    $isHeaderRow = false;
                    continue;
                }

                // izdvajamo prve dve kolone kao datume
                $fromDate = $this->normalizeDateCell($cells[0] ?? null);
                $toDate   = $this->normalizeDateCell($cells[1] ?? null);

                $rows[] = [
                    'sezona_od'    => $fromDate,
                    'sezona_do'    => $toDate,
                    'tip_jedinice' => $cells[2] ?? null,
                    'cena_noc'     => isset($cells[3]) && is_numeric($cells[3]) ? (float) $cells[3] : null,
                    'min_noci'     => isset($cells[4]) && is_numeric($cells[4]) ? (int) $cells[4] : null,
                    'doplate'      => $cells[5] ?? null,
                    'promo'        => $cells[6] ?? null,
                    // ceo red čuvamo i dalje u napomeni za debug
                    'napomena'     => json_encode($cells, JSON_UNESCAPED_UNICODE),
                ];
            }

            break; // samo prvi sheet
        }

        $reader->close();

        return $rows;
    }

    /**
     * Pretvara Excel cell u 'Y-m-d' ili null.
     */
    protected function normalizeDateCell($cell): ?string
    {
        if ($cell instanceof \DateTimeInterface) {
            return Carbon::instance($cell)->toDateString(); // 2026-06-01
        }

        if (is_string($cell) && trim($cell) !== '') {
            try {
                return Carbon::parse($cell)->toDateString();
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }
}
