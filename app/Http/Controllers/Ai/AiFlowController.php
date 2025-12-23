<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Services\InquiryAiExtractor;
use App\Services\InquiryAccommodationMatcher;
use App\Services\InquiryMissingData;
use App\Services\InquiryOfferDraftBuilder;
use Illuminate\Http\Request;

class AiFlowController extends Controller
{
    public function __construct(
        protected InquiryAiExtractor $extractor,
        protected InquiryAccommodationMatcher $matcher,
        protected InquiryOfferDraftBuilder $draftBuilder,
    ) {}

    public function handle(Request $request)
    {
        $rawText = $request->validate([
            'raw_text' => 'required|string',
        ])['raw_text'];

        // Ephemeral inquiry
        $inquiry = new Inquiry([
            'raw_message' => $rawText,
        ]);

        // 1) Extract
        $parsed = $this->extractor->extract($inquiry);

        // ✅ canonical json blobs (da matcher ima travel_time/date_window + party/groups + units)
        $inquiry->fill([
            'intent' => $parsed['intent'] ?? null,

            'entities'    => $parsed['entities'] ?? null,
            'travel_time' => $parsed['travel_time'] ?? null,
            'party'       => $parsed['party'] ?? null,
            'units'       => $parsed['units'] ?? null,
            'wishes'      => $parsed['wishes'] ?? null,
            'questions'   => $parsed['questions'] ?? null,
            'tags'        => $parsed['tags'] ?? null,
            'why_no_offer'=> $parsed['why_no_offer'] ?? null,

            // summary fields
            'region'    => $parsed['region'] ?? null,
            'location'  => $parsed['location'] ?? null,
            'month_hint'=> $parsed['month_hint'] ?? null,
            'date_from' => $parsed['date_from'] ?? null,
            'date_to'   => $parsed['date_to'] ?? null,
            'nights'    => $parsed['nights'] ?? null,

            'adults'       => $parsed['adults'] ?? null,
            'children'     => $parsed['children'] ?? null,
            'children_ages'=> $parsed['children_ages'] ?? null,

            'budget_min' => $parsed['budget_min'] ?? null,
            'budget_max' => $parsed['budget_max'] ?? null,

            'wants_near_beach' => $parsed['wants_near_beach'] ?? null,
            'wants_parking'    => $parsed['wants_parking'] ?? null,
            'wants_quiet'      => $parsed['wants_quiet'] ?? null,
            'wants_pets'       => $parsed['wants_pets'] ?? null,
            'wants_pool'       => $parsed['wants_pool'] ?? null,

            'special_requirements' => $parsed['special_requirements'] ?? null,
            'language'        => $parsed['language'] ?? 'sr',
            'extraction_mode' => $parsed['_mode'] ?? null,
            'extraction_debug'=> $parsed,
        ]);

        // 2) Missing data gate (✅ sad dozvoljava date_window + nights)
        $missing = InquiryMissingData::detect($inquiry);
        if (! empty($missing)) {
            return response()->json([
                'raw_text' => $rawText,
                'parsed_inquiry' => $parsed,
                'missing_fields' => $missing,
                'suggestions' => [
                    'primary' => [],
                    'alternatives' => [],
                    'log' => [
                        'reason' => 'missing_required_fields_for_offer',
                    ],
                ],
                'draft_reply' => null,
            ], 200, [], JSON_UNESCAPED_UNICODE);
        }

        // 3) Match
        $match = $this->matcher->matchWithAlternatives($inquiry, 5, 5);

        // ✅ matcher vraća arrays (ili u tvom slučaju može biti i array) -> uvek normalize
        $primary = collect($match['primary'] ?? []);
        $alts    = collect($match['alternatives'] ?? []);

        $chosen = $primary->isNotEmpty() ? $primary : $alts;

        // 4) Draft
        $draft = $this->draftBuilder->build($inquiry, $chosen);

        return response()->json([
            'raw_text' => $rawText,
            'parsed_inquiry' => $parsed,
            'missing_fields' => [],
            'suggestions' => [
                'primary' => $primary->values()->all(),
                'alternatives' => $alts->values()->all(),
                'log' => $match['log'] ?? null,
            ],
            'draft_reply' => $draft,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
