<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PriceListRowResource\Pages;
use App\Filament\Resources\PriceListRowResource\RelationManagers;
use App\Models\PriceListRow;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;


use App\Models\PriceList;

class PriceListRowResource extends Resource
{
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }
    protected static ?string $model = PriceListRow::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('price_list_id')
                    ->label('Cenovnik')
                    ->relationship('priceList', 'original_filename')
                    ->searchable()
                    ->preload()
                    ->required(),

                DatePicker::make('sezona_od')
                    ->label('Sezona od'),

                DatePicker::make('sezona_do')
                    ->label('Sezona do'),

                TextInput::make('tip_jedinice')
                    ->label('Tip jedinice')
                    ->placeholder('studio, 1/3, 1/4+1...')
                    ->maxLength(255),

                TextInput::make('cena_noc')
                    ->label('Cena po noći')
                    ->numeric()
                    ->step('0.01'),

                TextInput::make('min_noci')
                    ->label('Minimum noći')
                    ->numeric()
                    ->minValue(1),

                TextInput::make('doplate')
                    ->label('Doplate')
                    ->maxLength(255),

                TextInput::make('promo')
                    ->label('Promo')
                    ->placeholder('7=6, -10%...'),

                Textarea::make('napomena')
                    ->label('Napomena')
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

                TextColumn::make('sezona_od')
                    ->label('Od')
                    ->date()
                    ->sortable(),

                TextColumn::make('sezona_do')
                    ->label('Do')
                    ->date()
                    ->sortable(),

                TextColumn::make('tip_jedinice')
                    ->label('Tip')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('cena_noc')
                    ->label('Cena/noć')
                    ->sortable(),

                TextColumn::make('min_noci')
                    ->label('Min noći')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListPriceListRows::route('/'),
            'create' => Pages\CreatePriceListRow::route('/create'),
            'edit' => Pages\EditPriceListRow::route('/{record}/edit'),
        ];
    }
}
