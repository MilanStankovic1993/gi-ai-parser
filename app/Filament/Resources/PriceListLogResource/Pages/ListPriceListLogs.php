<?php

namespace App\Filament\Resources\PriceListLogResource\Pages;

use App\Filament\Resources\PriceListLogResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPriceListLogs extends ListRecords
{
    protected static string $resource = PriceListLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
