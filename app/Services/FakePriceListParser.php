<?php

namespace App\Services;

use App\Models\PriceList;
use App\Services\Contracts\PriceListParserInterface;

class FakePriceListParser implements PriceListParserInterface
{
    public function parse(PriceList $priceList): array
    {
        // Ovde ćemo kasnije koristiti OCR + AI,
        // za sada vraćamo hard-coded primer.

        return [
            [
                'sezona_od'    => '2026-06-01',
                'sezona_do'    => '2026-06-30',
                'tip_jedinice' => '1/2 studio',
                'cena_noc'     => 35.00,
                'min_noci'     => 7,
                'doplate'      => 'klima +5€ po danu',
                'promo'        => null,
                'napomena'     => 'standardna sezona',
            ],
            [
                'sezona_od'    => '2026-07-01',
                'sezona_do'    => '2026-07-31',
                'tip_jedinice' => '1/2 studio',
                'cena_noc'     => 50.00,
                'min_noci'     => 10,
                'doplate'      => 'klima uključena',
                'promo'        => '7=6 za rane uplate',
                'napomena'     => 'glavna sezona',
            ],
        ];
    }
}
