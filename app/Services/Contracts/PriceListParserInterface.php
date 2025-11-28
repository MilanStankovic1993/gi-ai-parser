<?php

namespace App\Services\Contracts;

use App\Models\PriceList;

interface PriceListParserInterface
{
    /**
     * Na osnovu datog PriceList modela parsira cenovnik
     * i vraća niz redova u standardizovanom formatu:
     *
     * [
     *   [
     *     'sezona_od'    => '2026-06-01',
     *     'sezona_do'    => '2026-06-30',
     *     'tip_jedinice' => '1/2 studio',
     *     'cena_noc'     => 35.0,
     *     'min_noci'     => 7,
     *     'doplate'      => '...',
     *     'promo'        => '...',
     *     'napomena'     => '...',
     *   ],
     *   ...
     * ]
     */
    public function parse(PriceList $priceList): array;
}
