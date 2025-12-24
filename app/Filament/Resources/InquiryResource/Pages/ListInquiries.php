<?php

namespace App\Filament\Resources\InquiryResource\Pages;

use App\Filament\Resources\InquiryResource;
use App\Filament\Widgets\AiUsageOverview;
use App\Models\Inquiry;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInquiries extends ListRecords
{
    protected static string $resource = InquiryResource::class;

    public function getDefaultActiveTab(): ?string
    {
        return 'open';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\AiUsageOverview::class,
        ];
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Open')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotIn('status', ['replied', 'closed']))
                ->badge(fn () => Inquiry::query()->whereNotIn('status', ['replied', 'closed'])->count()),

            'all' => Tab::make('All')
                ->badge(fn () => Inquiry::query()->count()),

            'replied' => Tab::make('Replied')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'replied'))
                ->badge(fn () => Inquiry::query()->where('status', 'replied')->count()),

            'closed' => Tab::make('Closed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'closed'))
                ->badge(fn () => Inquiry::query()->where('status', 'closed')->count()),

            'no_ai' => Tab::make('No AI')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'no_ai'))
                ->badge(fn () => Inquiry::query()->where('status', 'no_ai')->count()),
        ];
    }
}
