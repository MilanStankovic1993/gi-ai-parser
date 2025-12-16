<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Ai\AiFlowController;
use App\Http\Controllers\AiInquiryController;
use App\Http\Controllers\AiSuggestionController;

Route::get('/ping', fn () => response()->json(['ok' => true]));

Route::prefix('ai')->group(function () {
    Route::post('parse', [AiInquiryController::class, 'parse']);
    Route::post('find', [AiSuggestionController::class, 'find']);
    Route::post('flow', [AiFlowController::class, 'handle']);
});
