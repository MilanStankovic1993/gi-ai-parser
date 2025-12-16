<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

use App\Models\Grcka\Hotel;
use App\Http\Controllers\GrckaHotelController;
use App\Http\Controllers\AiInquiryController;
use App\Http\Controllers\AiSuggestionController;

Route::get('/', function () {
    return view('welcome');
});

/**
 * Lokalni test/debug endpointi.
 * Ne smeju da idu na produkciju.
 */
if (app()->environment('local')) {

    // =========================
    // AI: PARSE (browser test)
    // =========================
    Route::get('/ai/parse-test', function () {
        $raw = "Pozdrav, treba mi smeštaj u Haniotiju za 2 odrasle i 1 dete (5 godina), budžet oko 70 evra po noći, termin oko 15. jul.";

        // cache 60s da ne “ubiješ” OpenAI kad ga uključiš
        $key = 'ai:parse-test:' . md5($raw);

        return cache()->remember($key, 60, function () use ($raw) {
            $request = new Request(['raw_text' => $raw]);
            return app(AiInquiryController::class)->parse($request);
        });
    });

    // =========================
    // AI: FIND (browser test)
    // =========================
    Route::get('/ai/find-test', function () {
        $request = new Request([
            'region'           => 'Halkidiki',
            'location'         => null,
            'check_in'         => null,
            'nights'           => null,
            'adults'           => 2,
            'children'         => [
                ['age' => 5],
            ],
            'budget_per_night' => 70,
            'wants'            => [],
        ]);

        return app(AiSuggestionController::class)->find($request);
    });

    // =========================
    // DEBUG: hotels (quick peek)
    // =========================
    Route::get('/debug/grcka-hotels', function () {
        return Hotel::query()
            ->aiEligible()
            ->aiOrdered()
            ->with('rooms')
            ->limit(10)
            ->get([
                'hotel_id',
                'hotel_title',
                'hotel_city',
                'hotel_basic_price',
                'placen',
                'valid2025',
                'ai_order',
            ]);
    });

    // Optional: JSON list endpoint (ako ti treba)
    Route::get('/grcka-hoteli', [GrckaHotelController::class, 'index']);
}
