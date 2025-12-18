<?php

namespace App\Services;

use App\Models\PriceList;
use App\Services\Contracts\PriceListParserInterface;

class PriceListParsingService
{
    public function __construct(
        protected ExcelPriceListParser $excelParser,
        protected FakePriceListParser $fakeParser,
    ) {}

    /**
     * Glavna ulazna tačka za parsiranje cenovnika.
     */
    public function parse(PriceList $priceList): array
    {
        $filename = (string) ($priceList->original_filename ?? '');
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $parser = $this->resolveParserByExtension($ext);

        return $parser->parse($priceList);
    }

    protected function resolveParserByExtension(string $ext): PriceListParserInterface
    {
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            return $this->excelParser;
        }

        // dok ne ubaciš OCR/AI parser za PDF/slike
        return $this->fakeParser;
    }
}
