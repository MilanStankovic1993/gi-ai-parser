<?php

namespace App\Filament\Resources\PriceListLogResource\Pages;

use App\Filament\Resources\PriceListLogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPriceListLog extends EditRecord
{
    protected static string $resource = PriceListLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
