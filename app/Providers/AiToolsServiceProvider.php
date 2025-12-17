<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Ai\Email\EmailInboxProviderInterface;
use App\Ai\AiClient\AiClientInterface;

// Implementacije dodajemo u sledećim koracima:
use App\Ai\Email\Providers\LocalEmailInboxProvider;
use App\Ai\AiClient\Providers\FakeAiClient;

class AiToolsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmailInboxProviderInterface::class, function () {
            $provider = config('ai_tools.email_provider', 'local');

            return match ($provider) {
                'local' => app(LocalEmailInboxProvider::class),
                // 'imap' => app(ImapEmailInboxProvider::class), // kasnije
                default => app(LocalEmailInboxProvider::class),
            };
        });

        $this->app->bind(AiClientInterface::class, function () {
            $provider = config('ai_tools.ai_provider', 'fake');

            return match ($provider) {
                'fake' => app(FakeAiClient::class),
                // 'openai' => app(OpenAiClient::class), // kasnije
                default => app(FakeAiClient::class),
            };
        });
    }
}
