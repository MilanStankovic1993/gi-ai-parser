<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use App\Services\ExcelPriceListParser;
use App\Services\FakePriceListParser;
use App\Services\PriceListParsingService;
use App\Services\PriceListValidator;

use App\Services\InquiryAiExtractor;
use App\Services\InquiryAccommodationMatcher;
use App\Services\InquiryOfferDraftBuilder;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Price list parsers + orchestrator
        $this->app->singleton(ExcelPriceListParser::class);
        $this->app->singleton(FakePriceListParser::class);
        $this->app->singleton(PriceListParsingService::class);

        $this->app->singleton(PriceListValidator::class);

        // Inquiry flow (source of truth)
        $this->app->singleton(InquiryAiExtractor::class);
        $this->app->singleton(InquiryAccommodationMatcher::class);
        $this->app->singleton(InquiryOfferDraftBuilder::class);
    }

    public function boot(): void
    {
        //
    }
}
