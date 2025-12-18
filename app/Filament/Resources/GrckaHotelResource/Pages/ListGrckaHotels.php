<?php

namespace App\Filament\Resources\GrckaHotelResource\Pages;

use App\Filament\Resources\GrckaHotelResource;
use Filament\Resources\Pages\ListRecords;

class ListGrckaHotels extends ListRecords
{
    protected static string $resource = GrckaHotelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // read-only lista
        ];
    }
}
