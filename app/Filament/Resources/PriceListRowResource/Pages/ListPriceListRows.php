<?php

namespace App\Filament\Resources\PriceListRowResource\Pages;

use App\Filament\Resources\PriceListRowResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPriceListRows extends ListRecords
{
    protected static string $resource = PriceListRowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
