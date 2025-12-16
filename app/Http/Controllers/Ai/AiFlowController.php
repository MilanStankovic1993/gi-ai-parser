<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Http\Controllers\AiSuggestionController;
use App\Services\Ai\AiInquiryParser;
use App\Services\Ai\AiReplyGenerator;
use Illuminate\Http\Request;

class AiFlowController extends Controller
{
    public function __construct(
        protected AiInquiryParser $parser,
        protected AiReplyGenerator $reply,
    ) {}

    public function handle(Request $request)
    {
        $rawText = $request->validate([
            'raw_text' => 'required|string',
        ])['raw_text'];

        // 1) parse
        $parsed = $this->parser->parse($rawText);

        // 2) find suggestions (reuse existing controller for now)
        $findRequest = new Request([
            'region'           => $parsed['region'] ?? null,
            'location'         => $parsed['location'] ?? null,
            'check_in'         => $parsed['check_in'] ?? null,
            'nights'           => $parsed['nights'] ?? null,
            'adults'           => $parsed['adults'] ?? null,
            'children'         => $parsed['children'] ?? [],
            'budget_per_night' => $parsed['budget_per_night'] ?? null,
            'wants'            => $parsed['wants'] ?? [],
        ]);

        $suggestionsResp = app(AiSuggestionController::class)->find($findRequest);
        $suggestions = $suggestionsResp->getData(true)['results'] ?? [];

        // 3) reply (radi i kad je AI_ENABLED=false, jer imamo fallback)
        $draft = $this->reply->generate($rawText, $parsed, $suggestions);

        return response()->json([
            'raw_text'       => $rawText,
            'parsed_inquiry' => $parsed,
            'suggestions'    => $suggestions,
            'draft_reply'    => $draft,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
