<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InquiryResource\Pages;
use App\Models\Inquiry;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Arr;

class InquiryResource extends Resource
{
    protected static ?string $model = Inquiry::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';
    protected static ?string $navigationLabel = 'Inquiries';
    protected static ?string $navigationGroup = 'AI Inquiries';

    public static function form(Form $form): Form
    {
        return $form->schema([
            \Filament\Forms\Components\Section::make('Guest')
                ->schema([
                    \Filament\Forms\Components\TextInput::make('guest_name')->label('Ime gosta')->maxLength(255),
                    \Filament\Forms\Components\TextInput::make('guest_email')->label('Email')->email()->maxLength(255),
                    \Filament\Forms\Components\TextInput::make('guest_phone')->label('Telefon')->maxLength(255),
                ])
                ->columns(3),

            \Filament\Forms\Components\Section::make('Inquiry')
                ->schema([
                    \Filament\Forms\Components\TextInput::make('subject')->label('Naslov / subject')->maxLength(255),

                    \Filament\Forms\Components\Textarea::make('raw_message')
                        ->label('Originalni upit')
                        ->rows(8)
                        ->required(),

                    \Filament\Forms\Components\TextInput::make('region')->label('Regija')->maxLength(255),
                    \Filament\Forms\Components\TextInput::make('location')->label('Mesto')->maxLength(255),
                    \Filament\Forms\Components\TextInput::make('month_hint')->label('Okvirni period')->maxLength(255),

                    \Filament\Forms\Components\DatePicker::make('date_from')->label('Datum od'),
                    \Filament\Forms\Components\DatePicker::make('date_to')->label('Datum do'),

                    \Filament\Forms\Components\TextInput::make('adults')->label('Odrasli')->numeric()->minValue(0),
                    \Filament\Forms\Components\TextInput::make('children')->label('Deca')->numeric()->minValue(0),

                    // children_ages je cast array u modelu => u formi ga prikazujemo kao string,
                    // i pretvaramo nazad u array pre snimanja.
                    \Filament\Forms\Components\TextInput::make('children_ages')
                        ->label('Uzrast dece (npr: 5 ili 5, 8)')
                        ->maxLength(255)
                        ->formatStateUsing(function ($state) {
                            if (is_array($state)) {
                                return implode(', ', array_values($state));
                            }
                            return is_string($state) ? $state : null;
                        })
                        ->dehydrateStateUsing(function ($state) {
                            if ($state === null) {
                                return [];
                            }
                            if (is_array($state)) {
                                return array_values($state);
                            }

                            $txt = trim((string) $state);
                            if ($txt === '') {
                                return [];
                            }

                            $parts = preg_split('/[,\s;]+/', $txt) ?: [];
                            $nums = [];
                            foreach ($parts as $p) {
                                $n = preg_replace('/\D+/', '', (string) $p);
                                if ($n !== '') {
                                    $nums[] = (int) $n;
                                }
                            }
                            $nums = array_values(array_unique(array_filter($nums, fn ($n) => $n >= 0 && $n <= 17)));

                            return $nums;
                        }),

                    \Filament\Forms\Components\TextInput::make('budget_min')->label('Budžet min')->numeric()->minValue(0),
                    \Filament\Forms\Components\TextInput::make('budget_max')->label('Budžet max')->numeric()->minValue(0),

                    \Filament\Forms\Components\DateTimePicker::make('received_at')
                        ->label('Primljeno')
                        ->default(now()),
                ])
                ->columns(2),

            \Filament\Forms\Components\Section::make('Workflow / obrada')
                ->schema([
                    \Filament\Forms\Components\Select::make('reply_mode')
                        ->label('Način odgovora')
                        ->options([
                            'ai_draft' => 'AI draft',
                            'manual'   => 'Ručni',
                        ])
                        ->default('ai_draft')
                        ->required(),

                    \Filament\Forms\Components\Select::make('status')
                        ->label('Status upita')
                        ->options([
                            'new'       => 'New',
                            'extracted' => 'Extracted',
                            'suggested' => 'Suggested',
                            'replied'   => 'Replied',
                            'closed'    => 'Closed',
                        ])
                        ->default('new'),

                    \Filament\Forms\Components\Toggle::make('is_priority')->label('Prioritetan upit'),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),

                Tables\Columns\TextColumn::make('received_at')
                    ->label('Primljeno')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('guest_name')
                    ->label('Gost')
                    ->formatStateUsing(function ($state, Inquiry $record) {
                        if ($record->guest_email) {
                            return trim(($record->guest_name ?: 'N/A') . ' <' . $record->guest_email . '>');
                        }
                        return $record->guest_name ?: 'N/A';
                    })
                    ->searchable(query: function ($query, string $search) {
                        // pretraga i po email-u
                        $query->where(function ($q) use ($search) {
                            $q->where('guest_name', 'like', "%{$search}%")
                              ->orWhere('guest_email', 'like', "%{$search}%");
                        });
                    }),

                Tables\Columns\TextColumn::make('subject')
                    ->label('Upit')
                    ->limit(60)
                    ->tooltip(fn (Inquiry $record) => $record->raw_message ?: $record->subject)
                    ->searchable(),

                Tables\Columns\TextColumn::make('region')->label('Regija')->sortable()->toggleable(),

                // Filament v3: colors() mapa state => color
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'new'       => 'gray',
                        'extracted' => 'warning',
                        'suggested' => 'info',
                        'replied'   => 'success',
                        'closed'    => 'danger',
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'new'       => 'New',
                        'extracted' => 'Extracted',
                        'suggested' => 'Suggested',
                        'replied'   => 'Replied',
                        'closed'    => 'Closed',
                        default     => (string) $state,
                    })
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('reply_mode')
                    ->label('Odgovor')
                    ->colors([
                        'ai_draft' => 'primary',
                        'manual'   => 'secondary',
                    ])
                    ->formatStateUsing(fn ($state) => $state === 'ai_draft' ? 'AI draft' : 'Ručni'),
            ])
            ->filters([
                Tables\Filters\Filter::make('open_only')
                    ->label('Samo nerešeni')
                    ->query(fn ($query) => $query->whereNotIn('status', ['replied', 'closed'])),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'new'       => 'New',
                        'extracted' => 'Extracted',
                        'suggested' => 'Suggested',
                        'replied'   => 'Replied',
                        'closed'    => 'Closed',
                    ]),

                Tables\Filters\Filter::make('recent')
                    ->label('Poslednjih 7 dana')
                    ->query(fn ($query) => $query->where('received_at', '>=', now()->subDays(7))),

                Tables\Filters\SelectFilter::make('reply_mode')
                    ->label('Način odgovora')
                    ->options([
                        'ai_draft' => 'AI draft',
                        'manual'   => 'Ručni',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Open')->icon('heroicon-o-eye'),
            ])

            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInquiries::route('/'),
            'view'  => Pages\ViewInquiry::route('/{record}'),
        ];
    }
}
