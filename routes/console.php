<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Default inspire command
|--------------------------------------------------------------------------
*/
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| GI AI Parser – scheduled pipeline (Laravel 11/12)
|--------------------------------------------------------------------------
| Order:
| 1) IMAP pull -> ai_inquiries (raw inbox)
| 2) Sync      -> inquiries (business record)
| 3) Parse     -> extracted / needs_info
| 4) Suggest   -> suggestions_payload
|
| Notes:
| - runInBackground() da schedule:run ne blokira
| - withoutOverlapping() da se ne sudaraju
| - onOneServer() ako kasnije bude više instanci
|--------------------------------------------------------------------------
*/

// 1) IMAP ingest (Gmail) -> ai_inquiries
// Schedule::command('gi:imap-pull --limit=30')
//     ->everyThreeMinutes()
//     ->name('gi:imap-pull')
//     ->withoutOverlapping(5)
//     ->runInBackground()
//     ->onOneServer();

// // 2) Sync ai_inquiries -> inquiries
// Schedule::command('ai:sync-inquiries --limit=50')
//     ->everyThreeMinutes()
//     ->name('ai:sync-inquiries')
//     ->withoutOverlapping(5)
//     ->runInBackground()
//     ->onOneServer();

// // 3) Parse inquiries (može biti skuplje kad uključiš AI)
// Schedule::command('ai:parse --limit=50')
//     ->everyFiveMinutes()
//     ->name('ai:parse')
//     ->withoutOverlapping(10)
//     ->runInBackground()
//     ->onOneServer();

// // 4) Suggest hotels
// Schedule::command('ai:suggest --limit=50')
//     ->everyFiveMinutes()
//     ->name('ai:suggest')
//     ->withoutOverlapping(10)
//     ->runInBackground()
//     ->onOneServer();

/*
|--------------------------------------------------------------------------
| Optional: local ingest (ako ga koristiš)
|--------------------------------------------------------------------------
| Ako ga NE koristiš u produkciji, slobodno obriši.
*/
// Schedule::command('ai:ingest-local --limit=50')
//     ->everyFiveMinutes()
//     ->name('ai:ingest-local')
//     ->withoutOverlapping(10)
//     ->runInBackground()
//     ->onOneServer();
