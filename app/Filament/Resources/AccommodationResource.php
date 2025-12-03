<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccommodationResource\Pages;
use App\Models\Accommodation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;

class AccommodationResource extends Resource
{
    protected static ?string $model = Accommodation::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    protected static ?string $navigationLabel = 'Accommodations';
    protected static ?string $navigationGroup = 'AI Accommodations';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make('AccommodationTabs')
                    ->tabs([
                        // TAB 1 – svi osnovni podaci (ono što već imaš)
                        Tab::make('Osnovni podaci')
                            ->schema([
                                Forms\Components\Section::make('Osnovni podaci')
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Naziv objekta')
                                            ->required()
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('region')
                                            ->label('Regija')
                                            ->required()
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('settlement')
                                            ->label('Naselje / mesto')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('unit_type')
                                            ->label('Tip jedinice')
                                            ->placeholder('studio, apartman, duplex...')
                                            ->required()
                                            ->maxLength(255),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Kapacitet')
                                    ->schema([
                                        Forms\Components\TextInput::make('bedrooms')
                                            ->label('Broj spavaćih soba')
                                            ->numeric()
                                            ->minValue(0)
                                            ->helperText('0 = studio'),

                                        Forms\Components\TextInput::make('max_persons')
                                            ->label('Maksimalan broj osoba')
                                            ->numeric()
                                            ->minValue(1)
                                            ->required(),
                                    ])
                                    ->columns(2),

                                Forms\Components\Section::make('Plaža i okruženje')
                                    ->schema([
                                        Forms\Components\TextInput::make('distance_to_beach')
                                            ->label('Udaljenost od plaže (m)')
                                            ->numeric()
                                            ->minValue(0),

                                        Forms\Components\Select::make('beach_type')
                                            ->label('Tip plaže')
                                            ->options([
                                                'sand'     => 'Pesak',
                                                'pebble'   => 'Šljunak',
                                                'mixed'    => 'Mešano',
                                                'rocky'    => 'Stenovita',
                                            ])
                                            ->native(false),

                                        Forms\Components\Toggle::make('has_parking')
                                            ->label('Parking'),

                                        Forms\Components\Toggle::make('accepts_pets')
                                            ->label('Primaju ljubimce'),

                                        Forms\Components\Select::make('noise_level')
                                            ->label('Buka / pozicija')
                                            ->options([
                                                'quiet'     => 'Mirna lokacija',
                                                'street'    => 'Ulica',
                                                'main_road' => 'Magistrala',
                                            ])
                                            ->native(false),
                                    ])
                                    ->columns(3),

                                Forms\Components\Section::make('Interni podaci')
                                    ->schema([
                                        Forms\Components\Textarea::make('availability_note')
                                            ->label('Dostupnost (okvirno)')
                                            ->rows(3),

                                        Forms\Components\TextInput::make('internal_contact')
                                            ->label('Interni kontakt vlasnika')
                                            ->maxLength(255),

                                        Forms\Components\Toggle::make('is_commission')
                                            ->label('Provizijski objekat')
                                            ->default(true),

                                        Forms\Components\TextInput::make('priority')
                                            ->label('Prioritet (veće = važnije)')
                                            ->numeric()
                                            ->minValue(0)
                                            ->default(0),
                                    ])
                                    ->columns(2),
                            ]),

                        // TAB 2 – Cenovni periodi (Repeater preko pricePeriods relacije)
                        Tab::make('Cenovni periodi')
                            ->schema([
                                Forms\Components\Repeater::make('pricePeriods')
                                    ->label('Cenovni periodi')
                                    ->relationship('pricePeriods')
                                    ->defaultItems(0)
                                    ->createItemButtonLabel('Dodaj period')
                                    ->collapsible()
                                    ->schema([
                                        Forms\Components\TextInput::make('season_name')
                                            ->label('Naziv sezone')
                                            ->maxLength(255)
                                            ->helperText('Opcionalno: npr. Predsezona, Glavna sezona...'),

                                        Forms\Components\Grid::make()
                                            ->columns(2)
                                            ->schema([
                                                Forms\Components\DatePicker::make('date_from')
                                                    ->label('Datum od')
                                                    ->required(),

                                                Forms\Components\DatePicker::make('date_to')
                                                    ->label('Datum do')
                                                    ->required(),
                                            ]),

                                        Forms\Components\Grid::make()
                                            ->columns(3)
                                            ->schema([
                                                Forms\Components\TextInput::make('price_per_night')
                                                    ->label('Cena po noći (EUR)')
                                                    ->numeric()
                                                    ->minValue(0)
                                                    ->required(),

                                                Forms\Components\TextInput::make('min_nights')
                                                    ->label('Minimum noćenja')
                                                    ->numeric()
                                                    ->minValue(1)
                                                    ->default(1)
                                                    ->required(),

                                                Forms\Components\Toggle::make('is_available')
                                                    ->label('Dostupno')
                                                    ->default(true),
                                            ]),

                                        Forms\Components\TextInput::make('note')
                                            ->label('Napomena')
                                            ->maxLength(255),
                                    ]),
                            ]),
                    ])
                    ->persistTabInQueryString(), // pamti koji tab je otvoren
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Naziv')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('region')
                    ->label('Regija')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('settlement')
                    ->label('Naselje')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('unit_type')
                    ->label('Tip jedinice')
                    ->sortable(),

                Tables\Columns\TextColumn::make('max_persons')
                    ->label('Max osoba')
                    ->sortable(),

                Tables\Columns\IconColumn::make('has_parking')
                    ->label('Parking')
                    ->boolean(),

                Tables\Columns\IconColumn::make('accepts_pets')
                    ->label('Ljubimci')
                    ->boolean(),

                Tables\Columns\TextColumn::make('distance_to_beach')
                    ->label('Plaža (m)')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioritet')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_commission')
                    ->label('Provizija')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\Filter::make('commission_only')
                    ->label('Samo provizijski')
                    ->query(fn ($query) => $query->where('is_commission', true)),

                Tables\Filters\SelectFilter::make('region')
                    ->label('Regija')
                    ->options(fn () => Accommodation::query()
                        ->select('region')
                        ->distinct()
                        ->orderBy('region')
                        ->pluck('region', 'region')
                        ->toArray()
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccommodations::route('/'),
            'create' => Pages\CreateAccommodation::route('/create'),
            'view' => Pages\ViewAccommodation::route('/{record}'),
            'edit' => Pages\EditAccommodation::route('/{record}/edit'),
        ];
    }
}
