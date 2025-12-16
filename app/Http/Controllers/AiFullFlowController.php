<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiInquiryParser;
use App\Http\Controllers\AiSuggestionController;
use App\Services\Ai\AiReplyGenerator;
use Illuminate\Http\Request;
use App\Services\Ai\AiReplyAdapter;

class AiFullFlowController extends Controller
{
    public function __construct(
        protected AiInquiryParser $parser,
        protected AiReplyAdapter $replyAdapter
    ) {}

    public function handle(Request $request)
    {
        $rawText = $request->validate(['raw_text' => 'required|string'])['raw_text'];

        $parsed = $this->parser->parse($rawText);

        $suggestionsResp = app(AiSuggestionController::class)->find(
            new Request($parsed)
        );

        $suggestions = $suggestionsResp->getData(true)['results'] ?? [];

        // OVO JE JEDINA PROMENA:
        $reply = $this->replyAdapter->generateFromPayload([
            'raw_text'         => $rawText,
            'parsed'           => $parsed,
            'suggested_hotels' => $suggestions,
        ]);

        return response()->json([
            'raw_text'       => $rawText,
            'parsed_inquiry' => $parsed,
            'suggestions'    => $suggestions,
            'draft_reply'    => $reply,
        ]);
    }
}