<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PriceListLogResource\Pages;
use App\Filament\Resources\PriceListLogResource\RelationManagers;
use App\Models\PriceListLog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

use App\Models\PriceList;

class PriceListLogResource extends Resource
{
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
    protected static ?string $model = PriceListLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('price_list_id')
                    ->label('Price list ID')
                    ->disabled(),

                TextInput::make('step')
                    ->label('Korak obrade')
                    ->disabled(),

                Textarea::make('raw_input')
                    ->label('Raw input')
                    ->rows(8)
                    ->disabled()
                    ->columnSpanFull(),

                Textarea::make('raw_output')
                    ->label('Raw output')
                    ->rows(8)
                    ->disabled()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable()
                    ->label('ID'),

                TextColumn::make('priceList.original_filename')
                    ->label('Cenovnik')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('step')
                    ->label('Korak')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Kreirano')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(), // samo view
                // može i EditAction ako želiš da menjaš logove ručno
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPriceListLogs::route('/'),
            'create' => Pages\CreatePriceListLog::route('/create'),
            'edit' => Pages\EditPriceListLog::route('/{record}/edit'),
        ];
    }
}
