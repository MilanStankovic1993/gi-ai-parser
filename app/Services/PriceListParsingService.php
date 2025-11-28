<?php

namespace App\Services;

use App\Models\PriceList;
use App\Services\Contracts\PriceListParserInterface;

class PriceListParsingService
{
    /**
     * Glavna ulazna tačka za parsiranje cenovnika.
     */
    public function parse(PriceList $priceList): array
    {
        $filename = $priceList->original_filename ?? '';
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Za xlsx/xls fajlove koristimo Excel parser
        if (in_array($ext, ['xlsx', 'xls'])) {
            $parser = new ExcelPriceListParser();
        } else {
            // Za sada za sve ostalo otpada na fake parser
            $parser = new FakePriceListParser();
        }

        return $parser->parse($priceList);
    }
}
