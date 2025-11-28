<?php

namespace App\Filament\Resources\PriceListRowResource\Pages;

use App\Filament\Resources\PriceListRowResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPriceListRow extends EditRecord
{
    protected static string $resource = PriceListRowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
