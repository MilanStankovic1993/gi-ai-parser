<?php

namespace App\Services\Ai;

use App\Services\Ai\Dto\ParsedInquiryDto;
use Carbon\Carbon;

class AiReplyAdapter
{
    public function __construct(
        protected AiReplyGenerator $generator,
    ) {}

    /**
     * FullFlow šalje jedan ARRAY.
     * Ovaj adapter ga pretvara u originalni format:
     *   string $rawText,
     *   ParsedInquiryDto $parsed,
     *   array $suggestedHotels
     */
    public function generateFromPayload(array $payload): string
    {
        $rawText = $payload['raw_text'] ?? '';

        // Ako NEMA ParsedInquiryDto — pravimo ga ručno.
        $parsedArray = $payload['parsed'] ?? [];

        // check_in: u parseru je string (YYYY-MM-DD) ili null -> ovde ga pretvaramo u Carbon
        $checkIn = null;
        if (! empty($parsedArray['check_in'])) {
            try {
                $checkIn = Carbon::parse($parsedArray['check_in']);
            } catch (\Throwable $e) {
                $checkIn = null;
            }
        }

        $parsedDto = new ParsedInquiryDto(
            region: $parsedArray['region'] ?? null,
            location: $parsedArray['location'] ?? null,
            check_in: $checkIn,
            nights: $parsedArray['nights'] ?? null,
            adults: $parsedArray['adults'] ?? null,
            children: $parsedArray['children'] ?? [],
            budgetPerNight: $parsedArray['budget_per_night'] ?? null,
            wants: $parsedArray['wants'] ?? [],
            language: $parsedArray['language'] ?? 'sr'
        );

        $hotels = $payload['suggested_hotels'] ?? [];

        return $this->generator->generateDraftReply(
            $rawText,
            $parsedDto,
            $hotels
        );
    }

}
