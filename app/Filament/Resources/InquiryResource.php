<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InquiryResource\Pages;
use App\Models\Inquiry;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InquiryResource extends Resource
{
    protected static ?string $model = Inquiry::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';
    protected static ?string $navigationLabel = 'Inquiries';
    protected static ?string $navigationGroup = 'AI Inquiries';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Section::make('Guest')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('guest_name')
                            ->label('Ime gosta')
                            ->maxLength(255),

                        \Filament\Forms\Components\TextInput::make('guest_email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),

                        \Filament\Forms\Components\TextInput::make('guest_phone')
                            ->label('Telefon')
                            ->maxLength(255),
                    ])
                    ->columns(3),

                \Filament\Forms\Components\Section::make('Inquiry')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('subject')
                            ->label('Naslov / subject')
                            ->maxLength(255),

                        \Filament\Forms\Components\Textarea::make('raw_message')
                            ->label('Originalni upit')
                            ->rows(8)
                            ->required(),

                        \Filament\Forms\Components\TextInput::make('region')
                            ->label('Regija')
                            ->maxLength(255),

                        \Filament\Forms\Components\DatePicker::make('date_from')
                            ->label('Datum od'),

                        \Filament\Forms\Components\DatePicker::make('date_to')
                            ->label('Datum do'),

                        \Filament\Forms\Components\TextInput::make('adults')
                            ->label('Odrasli')
                            ->numeric()
                            ->minValue(0),

                        \Filament\Forms\Components\TextInput::make('children')
                            ->label('Deca')
                            ->numeric()
                            ->minValue(0),

                        \Filament\Forms\Components\TextInput::make('budget_min')
                            ->label('Budžet min')
                            ->numeric()
                            ->minValue(0),

                        \Filament\Forms\Components\TextInput::make('budget_max')
                            ->label('Budžet max')
                            ->numeric()
                            ->minValue(0),

                        \Filament\Forms\Components\DateTimePicker::make('received_at')
                            ->label('Primljeno')
                            ->default(now()),
                    ])
                    ->columns(2),

                // 🔹 NOVO: Workflow sekcija
                \Filament\Forms\Components\Section::make('Workflow / obrada')
                    ->schema([
                        \Filament\Forms\Components\Select::make('reply_mode')
                            ->label('Način odgovora')
                            ->options([
                                'ai_draft' => 'AI draft (pripremi automatski predlog mejla)',
                                'manual'   => 'Ručni odgovor (bez AI drafa)',
                            ])
                            ->default('ai_draft')
                            ->required()
                            ->helperText('Ovim birate da li će operater koristiti AI draft kao osnovu, ili će ceo odgovor pisati ručno.'),

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

                        \Filament\Forms\Components\Toggle::make('is_priority')
                            ->label('Prioritetan upit')
                            ->helperText('Za hitne ili bitne goste.'),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

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
                    ->searchable(),

                Tables\Columns\TextColumn::make('subject')
                    ->label('Upit')
                    ->limit(60)
                    ->tooltip(fn (Inquiry $record) => $record->raw_message)
                    ->searchable(),

                Tables\Columns\TextColumn::make('region')
                    ->label('Regija')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'gray'    => 'new',
                        'warning' => 'extracted',
                        'info'    => 'suggested',
                        'success' => 'replied',
                        'danger'  => 'closed',
                    ])
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'new'       => 'New',
                        'extracted' => 'Extracted',
                        'suggested' => 'Suggested',
                        'replied'   => 'Replied',
                        'closed'    => 'Closed',
                        default     => $state,
                    })
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('reply_mode')
                    ->label('Odgovor')
                    ->colors([
                        'primary'   => 'ai_draft',
                        'secondary' => 'manual',
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
                Tables\Actions\ViewAction::make()
                    ->label('Open')
                    ->icon('heroicon-o-eye'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // kasnije: mark as priority, change reply mode, itd.
                ]),
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
