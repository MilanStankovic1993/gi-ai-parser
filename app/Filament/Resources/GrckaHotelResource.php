<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GrckaHotelResource\Pages;
use App\Models\Grcka\Hotel;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GrckaHotelResource extends Resource
{
    protected static ?string $model = Hotel::class;

    protected static ?string $navigationIcon  = 'heroicon-o-building-office-2';
    protected static ?string $navigationLabel = 'Hoteli';
    protected static ?string $navigationGroup = 'AI Inquiries';
    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // cela baza kao kod njih
        return parent::getEloquentQuery();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->deferLoading()
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)

            ->modifyQueryUsing(function (Builder $query, Table $table) {
                // location label accessor ti je dobar kad eager-loaduješ region
                $query->with(['location.region']);

                // agregati: uključujemo ih samo kad je capacity filter aktivan ili kad user sortira po agregat koloni.
                $capacityActive = false;
                $sortColumn = null;

                try {
                    $lw = $table->getLivewire();
                    $sortColumn = method_exists($lw, 'getTableSortColumn') ? $lw->getTableSortColumn() : null;

                    $filterState = method_exists($lw, 'getTableFilterState') ? $lw->getTableFilterState() : [];
                    $cap = $filterState['capacity'] ?? [];

                    $capacityActive = (
                        (isset($cap['adults']) && $cap['adults'] !== '' && $cap['adults'] !== null) ||
                        (isset($cap['children']) && $cap['children'] !== '' && $cap['children'] !== null) ||
                        (isset($cap['min_stay']) && $cap['min_stay'] !== '' && $cap['min_stay'] !== null)
                    );
                } catch (\Throwable) {
                    // ignore
                }

                $sortNeedsAggregates = in_array($sortColumn, [
                    'min_basic_price', 'max_adults', 'max_children', 'min_min_stay',
                ], true);

                if ($sortNeedsAggregates || $capacityActive) {
                    $query
                        ->withMin(['rooms as min_basic_price' => fn ($q) => $q], 'room_basic_price')
                        ->withMax(['rooms as max_adults' => fn ($q) => $q], 'room_adults')
                        ->withMax(['rooms as max_children' => fn ($q) => $q], 'room_children')
                        ->withMin(['rooms as min_min_stay' => fn ($q) => $q], 'room_min_stay');
                }
            })

            ->defaultSort('hotel_id', 'desc')

            ->columns([
                Tables\Columns\TextColumn::make('hotel_id')->label('ID')->sortable(),

                Tables\Columns\TextColumn::make('hotel_title')
                    ->label('Hotel')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('location_label')
                    ->label('Location')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('min_basic_price')
                    ->label('Min €')
                    ->formatStateUsing(fn ($state) => $state ? number_format((float) $state, 0, ',', '.') . ' €' : '-')
                    ->sortable(query: function (Builder $query, string $direction) {
                        $query
                            ->orderByRaw('min_basic_price IS NULL')
                            ->orderBy('min_basic_price', $direction);
                    })
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('ai_order')
                    ->label('AI order')
                    ->sortable(query: function (Builder $query, string $direction) {
                        $query
                            ->orderByRaw('ai_order IS NULL')
                            ->orderBy('ai_order', $direction)
                            ->orderByDesc('sortOrder')
                            ->orderBy('hotel_id');
                    })
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\BadgeColumn::make('hotel_status')
                    ->label('Status')
                    ->colors([
                        'Yes' => 'success',
                        'No'  => 'danger',
                    ])
                    ->sortable(),

                Tables\Columns\IconColumn::make('booking')
                    ->label('Booking=YES')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('cene2024')
                    ->label('Cene 2024=YES')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('hotel_email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('custom_email')
                    ->label('Custom email')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('public_url')
                    ->label('Link')
                    ->url(fn (Hotel $record) => $record->public_url ?: null, true)
                    ->openUrlInNewTab()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])

            ->filters([
                Tables\Filters\TernaryFilter::make('ai_eligible')
                    ->label('AI eligible (provizijski)')
                    ->placeholder('Svi')
                    ->trueLabel('Samo AI eligible')
                    ->falseLabel('Bez AI eligible')
                    ->queries(
                        true: fn (Builder $q) => $q->aiEligible(),
                        false: fn (Builder $q) => $q->where(function (Builder $w) {
                            $w->where('hotel_status', '!=', 'Yes')
                              ->orWhere('booking', '!=', 1)
                              ->orWhere('cene2024', '!=', 1);
                        }),
                    ),

                Tables\Filters\Filter::make('region_like')
                    ->label('Pretraga lokacije (tekst)')
                    ->form([
                        Forms\Components\TextInput::make('q')
                            ->label('Location sadrži')
                            ->placeholder('npr. Nikiti, Pefkohori, Kavala...')
                            ->live(debounce: 700),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $q = trim((string) ($data['q'] ?? ''));
                        if ($q === '') {
                            return $query;
                        }

                        return $query->where(function (Builder $qq) use ($q) {
                            $qq->where('mesto', 'like', "%{$q}%")
                               ->orWhere('hotel_map_city', 'like', "%{$q}%")
                               ->orWhere('hotel_city', 'like', "%{$q}%");
                        });
                    }),

                Tables\Filters\Filter::make('capacity')
                    ->label('Kapacitet / min-stay (rooms)')
                    ->form([
                        Forms\Components\TextInput::make('adults')->numeric()->minValue(0)->label('Odrasli (min)'),
                        Forms\Components\TextInput::make('children')->numeric()->minValue(0)->label('Deca (min)'),
                        Forms\Components\TextInput::make('min_stay')
                            ->numeric()->minValue(0)
                            ->label('Noćenja (min-stay <=)')
                            ->helperText('Hotel mora imati bar jednu sobu gde je room_min_stay <= ovaj broj (ili NULL).'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $adults   = ($data['adults'] ?? null);
                        $children = ($data['children'] ?? null);
                        $minStay  = ($data['min_stay'] ?? null);

                        $adults   = ($adults === '' || $adults === null) ? null : (int) $adults;
                        $children = ($children === '' || $children === null) ? null : (int) $children;
                        $minStay  = ($minStay === '' || $minStay === null) ? null : (int) $minStay;

                        if ($adults === 0) $adults = null;
                        if ($children === 0) $children = null;
                        if ($minStay === 0) $minStay = null;

                        if ($adults === null && $children === null && $minStay === null) {
                            return $query;
                        }

                        return $query->whereHas('rooms', function (Builder $r) use ($adults, $children, $minStay) {
                            if ($adults !== null)   $r->where('room_adults', '>=', $adults);
                            if ($children !== null) $r->where('room_children', '>=', $children);

                            if ($minStay !== null) {
                                $r->where(function (Builder $qq) use ($minStay) {
                                    $qq->whereNull('room_min_stay')
                                       ->orWhere('room_min_stay', '<=', $minStay);
                                });
                            }
                        });
                    }),

                Tables\Filters\Filter::make('active_only')
                    ->label('Samo aktivni')
                    ->query(fn (Builder $query) => $query->where('hotel_status', 'Yes')),

                Tables\Filters\Filter::make('booking_only')
                    ->label('Booking=YES')
                    ->query(fn (Builder $query) => $query->where('booking', 1)),

                Tables\Filters\Filter::make('prices_only')
                    ->label('Cene 2024=YES')
                    ->query(fn (Builder $query) => $query->where('cene2024', 1)),
            ])
            ->actions([])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGrckaHotels::route('/grcka-hotels'),
        ];
    }
}
