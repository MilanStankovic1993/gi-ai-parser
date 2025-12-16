<?php

namespace App\Services\Ai;

class AiInquiryFlowService
{
    public function __construct(
        protected AiInquiryParser $parser,
        protected SuggestionService $sugg,
        protected AiReplyGenerator $reply
    ) {}

    public function handle(string $rawText): array
    {
        $parsed = $this->parser->parse($rawText);
        $suggestions = $this->sugg->get($parsed);
        $draft = $this->reply->generate($rawText, $parsed, $suggestions);

        return [
            'raw_text' => $rawText,
            'parsed_inquiry' => $parsed,
            'suggestions' => $suggestions,
            'draft_reply' => $draft,
        ];
    }
}
