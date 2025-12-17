<?php

return [
    'email_provider' => env('AI_EMAIL_PROVIDER', 'local'), // local|imap
    'ai_provider'    => env('AI_PROVIDER', 'fake'),        // fake|openai

    // local provider
    'local_inbox_path' => env('AI_LOCAL_INBOX_PATH', storage_path('app/ai-inbox')),

    // IMAP (kasnije)
    'imap' => [
        'host'     => env('AI_IMAP_HOST'),
        'port'     => (int) env('AI_IMAP_PORT', 993),
        'encryption' => env('AI_IMAP_ENCRYPTION', 'ssl'),
        'username' => env('AI_IMAP_USERNAME'),
        'password' => env('AI_IMAP_PASSWORD'),
        'mailbox'  => env('AI_IMAP_MAILBOX', 'INBOX'),
    ],

    // OpenAI (kasnije)
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model'   => env('OPENAI_MODEL', 'gpt-4.1-mini'),
    ],
];
