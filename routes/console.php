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
| GI AI Parser – scheduled pipeline (Laravel 11)
|--------------------------------------------------------------------------
| Redosled:
| 1) ingest local emails  -> ai_inquiries
| 2) sync ai_inquiries    -> inquiries
| 3) parse inquiries     -> extracted / needs_info
| 4) suggest hotels      -> suggestions_payload
|
| IMAP varijanta je isključena (ostavljena kao komentar).
|--------------------------------------------------------------------------
*/

// -------------------------------------------------
// 1) Ingest LOCAL inbox (.eml / .txt)
// -------------------------------------------------
Schedule::command('ai:ingest-local --limit=50')
    ->everyTwoMinutes()
    ->withoutOverlapping();

// -------------------------------------------------
// 2) Sync ai_inquiries -> inquiries
// -------------------------------------------------
Schedule::command('ai:sync-inquiries --limit=50')
    ->everyTwoMinutes()
    ->withoutOverlapping();

// -------------------------------------------------
// 3) Parse inquiries (AI ili fallback)
// -------------------------------------------------
Schedule::command('ai:parse --limit=50')
    ->everyTwoMinutes()
    ->withoutOverlapping();

// -------------------------------------------------
// 4) Suggest hotels (primary + alternatives)
// -------------------------------------------------
Schedule::command('ai:suggest --limit=50')
    ->everyTwoMinutes()
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| IMAP variant (PRODUCTION) – trenutno ISKLJUČENO
|--------------------------------------------------------------------------
| Kada pređeš na IMAP, samo:
| 1) zakomentariši ai:ingest-local
| 2) odkomentariši ovo ispod
|--------------------------------------------------------------------------
|
| Schedule::command('gi:imap-pull --limit=50')
|     ->everyTwoMinutes()
|     ->withoutOverlapping();
|
*/
