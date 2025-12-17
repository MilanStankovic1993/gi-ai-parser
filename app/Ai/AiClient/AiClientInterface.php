<?php

namespace App\Ai\AiClient;

interface AiClientInterface
{
    /**
     * @return array<string, mixed> extracted params (standardizovan oblik)
     */
    public function extractParams(string $emailText): array;

    /**
     * @param array<string, mixed> $context
     */
    public function generateDraft(array $context): string;
}
