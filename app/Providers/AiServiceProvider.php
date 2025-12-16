<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use OpenAI; // <-- VAŽNO (facade-style static)
use App\Services\Ai\OpenAiClient;
use App\Services\Ai\AiInquiryParser;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OpenAiClient::class, function () {
            $apiKey = (string) config('services.openai.api_key');
            $model  = (string) config('services.openai.model', 'gpt-4.1');

            $client = OpenAI::client($apiKey);

            return new OpenAiClient($client, $model);
        });

        $this->app->singleton(AiInquiryParser::class, fn($app) =>
            new AiInquiryParser($app->make(OpenAiClient::class))
        );
    }
}
