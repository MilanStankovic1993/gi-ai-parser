<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiInquiryParser;
use Illuminate\Http\Request;

class AiInquiryController extends Controller
{
    public function __construct(
        protected AiInquiryParser $parser,
    ) {}

    public function parse(Request $request)
    {
        // 1) Validacija – očekujemo samo raw_text
        $validated = $request->validate([
            'raw_text' => 'required|string',
        ]);

        $rawText = $validated['raw_text'];

        // 2) Zovemo naš mali parser (za sada heuristika, kasnije AI)
        $parsed = $parserResult = $this->parser->parse($rawText);

        // 3) Vraćamo strukturirani odgovor
        return response()->json([
            'raw_text' => $rawText,
            'parsed'   => $parsed,
        ]);
    }
}
