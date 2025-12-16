<?php

namespace App\Services\Ai\Dto;

use Carbon\Carbon;

class ParsedInquiryDto
{
    public function __construct(
        public readonly ?string $region = null,
        public readonly ?string $location = null,
        public readonly ?Carbon $check_in = null,
        public readonly ?int $nights = null,
        public readonly ?int $adults = null,
        /** @var array<int, array{age:int|null}> */
        public readonly array $children = [],
        public readonly ?float $budgetPerNight = null,
        /** @var array<int, string> */
        public readonly array $wants = [],
        public readonly string $language = 'sr',
    ) {}
}
