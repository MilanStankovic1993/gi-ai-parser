<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use App\Ai\Email\EmailInboxProviderInterface;
use App\Ai\AiClient\AiClientInterface;

use App\Ai\Email\Providers\LocalEmailInboxProvider;
// use App\Ai\Email\Providers\ImapEmailInboxProvider; // kasnije

use App\Ai\AiClient\Providers\FakeAiClient;

use App\Services\Ai\OpenAiClient;
use OpenAI;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /**
         * BITNO:
         * AiInquiryParser i AiReplyGenerator zavise od OpenAiClient u konstruktoru,
         * pa container MORA moći da ga instancira čak i kad je AI_ENABLED=false.
         *
         * Kada je AI_ENABLED=false, vraćamo dummy SDK client koji nikad neće biti pozvan
         * (jer parser/generator već imaju fallback grane).
         */
        $this->app->singleton(OpenAiClient::class, function () {
            $model = (string) config('services.openai.model', 'gpt-4.1');

            $aiEnabled = filter_var(env('AI_ENABLED', false), FILTER_VALIDATE_BOOL);

            if (! $aiEnabled) {
                $dummySdk = new class {
                    public function chat()
                    {
                        return new class {
                            public function create(array $args)
                            {
                                throw new \RuntimeException('OpenAI is disabled (AI_ENABLED=false).');
                            }
                        };
                    }
                };

                return new OpenAiClient($dummySdk, $model);
            }

            $apiKey = (string) config('services.openai.api_key');
            if ($apiKey === '') {
                throw new \RuntimeException('Missing OpenAI API key. Set AI_ENABLED=false or configure services.openai.api_key.');
            }

            $sdk = OpenAI::client($apiKey);

            return new OpenAiClient($sdk, $model);
        });

        // 1) EMAIL provider switch (local/imap)
        $this->app->bind(EmailInboxProviderInterface::class, function () {
            $provider = config('ai_tools.email_provider', 'local');

            return match ($provider) {
                'local' => app(LocalEmailInboxProvider::class),
                // 'imap'  => app(ImapEmailInboxProvider::class), // kasnije
                default => app(LocalEmailInboxProvider::class),
            };
        });

        // 2) AI provider switch (fake/openai) - ovo nam još ne treba za parser (koristimo AI_ENABLED)
        $this->app->bind(AiClientInterface::class, function () {
            $provider = config('ai_tools.ai_provider', 'fake');

            return match ($provider) {
                'fake'  => app(FakeAiClient::class),
                'openai' => $this->makeOpenAiAiClientAdapter(), // kasnije kad napravimo wrapper
                default => app(FakeAiClient::class),
            };
        });
    }

    private function makeOpenAiAiClientAdapter(): AiClientInterface
    {
        // još uvek ne koristimo ovaj put
        throw new \RuntimeException('OpenAI provider not implemented yet. Set AI_PROVIDER=fake.');
    }
}
