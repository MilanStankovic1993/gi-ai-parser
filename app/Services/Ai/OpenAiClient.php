<?php

namespace App\Services\Ai;

use OpenAI\Exceptions\RateLimitException;

class OpenAiClient
{
    public function __construct(
        protected $client, // OpenAI\Client (ne moramo tipovati da ne pucamo)
        protected string $model,
    ) {}


    public function extractJson(string $systemPrompt, string $userPrompt): array
    {
        try {
            return $this->withRetry(function () use ($systemPrompt, $userPrompt) {
                $response = $this->client->chat()->create([
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                ]);

                $content = $response->choices[0]->message->content ?? '';
                $decoded = json_decode($content, true);

                return is_array($decoded) ? $decoded : [];
            });
        } catch (RateLimitException $e) {
            \Log::warning('OpenAI rate limit (extractJson)', ['message' => $e->getMessage()]);
            return []; // <- BITNO: nema 500
        }
    }

    public function generateText(string $systemPrompt, string $userPrompt): string
    {
        return (string) $this->withRetry(function () use ($systemPrompt, $userPrompt) {
            $response = $this->client->chat()->create([
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

            return $response->choices[0]->message->content ?? '';
        });
    }

    private function withRetry(callable $fn)
    {
        $attempts = 5;
        $baseSleepMs = 800;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                return $fn();
            } catch (\OpenAI\Exceptions\RateLimitException $e) {
                // 429 - obavezno backoff
                if ($i === $attempts) {
                    throw $e;
                }

                usleep(($baseSleepMs * $i * $i + random_int(0, 400)) * 1000);
                continue;
            } catch (\Throwable $e) {
                $msg = strtolower($e->getMessage());
                $isRate = str_contains($msg, 'rate limit') || str_contains($msg, '429');

                if (! $isRate || $i === $attempts) {
                    throw $e;
                }

                usleep(($baseSleepMs * $i * $i + random_int(0, 400)) * 1000);
            }
        }
    }
}
