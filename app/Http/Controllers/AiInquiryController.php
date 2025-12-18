<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use App\Services\InquiryAiExtractor;
use Illuminate\Http\Request;

class AiInquiryController extends Controller
{
    public function __construct(
        protected InquiryAiExtractor $extractor,
    ) {}

    public function parse(Request $request)
    {
        $rawText = $request->validate([
            'raw_text' => 'required|string',
        ])['raw_text'];

        $inquiry = new Inquiry(['raw_message' => $rawText]);
        $parsed  = $this->extractor->extract($inquiry);

        return response()->json([
            'raw_text' => $rawText,
            'parsed'   => $parsed,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
