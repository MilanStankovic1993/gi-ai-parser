<?php

namespace App\Ai\AiClient\Providers;

use App\Ai\AiClient\AiClientInterface;

class FakeAiClient implements AiClientInterface
{
    public function extractParams(string $emailText): array
    {
        return [];
    }

    public function generateDraft(array $context): string
    {
        return '';
    }
}
