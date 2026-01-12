<?php

return [
    'monthly_limit_usd' => env('AI_MONTHLY_LIMIT_USD', 50),
    'usd_to_eur' => env('AI_USD_TO_EUR', 0.92),

    // IMAP ingest settings (config:cache friendly)
    'imap' => [
        'mailbox' => env('AI_IMAP_MAILBOX', 'INBOX'),
        'lookback_days' => env('AI_IMAP_LOOKBACK_DAYS', 7),
        'fetch_mode' => env('AI_IMAP_FETCH_MODE', 'unseen'), // unseen|all
    ],
];
