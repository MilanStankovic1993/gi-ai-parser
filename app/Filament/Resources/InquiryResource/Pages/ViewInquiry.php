<?php

namespace App\Filament\Resources\InquiryResource\Pages;

use App\Filament\Resources\InquiryResource;
use App\Services\InquiryAiExtractor;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Tabs;
use Filament\Infolists\Components\Tabs\Tab;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use App\Services\InquiryAccommodationMatcher;
use App\Services\InquiryOfferDraftBuilder;

class ViewInquiry extends ViewRecord
{
    protected static string $resource = InquiryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('run_ai_extraction')
                ->label('Run AI extraction')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->requiresConfirmation()
                ->action(function () {
                    /** @var \App\Models\Inquiry $record */
                    $record = $this->record;

                    /** @var \App\Services\InquiryAiExtractor $extractor */
                    $extractor = app(InquiryAiExtractor::class);

                    $data = $extractor->extract($record);

                    $record->region     = $data['region']      ?? $record->region;
                    $record->date_from  = $data['date_from']   ?? $record->date_from;
                    $record->date_to    = $data['date_to']     ?? $record->date_to;
                    $record->nights     = $data['nights']      ?? $record->nights;
                    $record->adults     = $data['adults']      ?? $record->adults;
                    $record->children   = $data['children']    ?? $record->children;
                    $record->budget_min = $data['budget_min']  ?? $record->budget_min;
                    $record->budget_max = $data['budget_max']  ?? $record->budget_max;

                    $record->status       = 'extracted';
                    $record->processed_at = now();

                    $record->save();

                    Notification::make()
                        ->title('AI extraction je uspešno izvršena')
                        ->body('Trenutno koristi lokalni parser, kasnije ćemo ovde nakačiti pravi AI.')
                        ->success()
                        ->send();
                }),

                Actions\Action::make('generate_ai_draft')
                    ->label('Generate AI draft')
                    ->icon('heroicon-o-envelope')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function () {
                        /** @var \App\Models\Inquiry $record */
                        $record = $this->record;

                        // 1) na osnovu trenutnog stanja upita, pronađi kandidate
                        $matcher = app(InquiryAccommodationMatcher::class);
                        $candidates = $matcher->match($record, 5);

                        if ($candidates->isEmpty()) {
                            Notification::make()
                                ->title('Nema kandidata za ovaj upit')
                                ->body('Proveri da li su popunjeni datumi, regija i da postoje provizijski smeštaji sa cenama.')
                                ->warning()
                                ->send();

                            return;
                        }

                        // 2) generiši draft odgovora
                        $builder = app(InquiryOfferDraftBuilder::class);
                        $draft   = $builder->build($record, $candidates);

                        // 3) snimi na upit
                        $record->ai_draft = $draft;
                        $record->status   = 'suggested';
                        $record->save();

                        Notification::make()
                            ->title('AI draft je generisan')
                            ->body('Draft odgovora je spreman za pregled i eventualne izmene.')
                            ->success()
                            ->send();
                    }),
                    
                Actions\Action::make('mark_as_replied')
                    ->label('Mark as replied')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn () => $this->record->status !== 'replied')
                    ->requiresConfirmation()
                    ->action(function () {
                        /** @var \App\Models\Inquiry $record */
                        $record = $this->record;

                        $record->status = 'replied';
                        $record->save();

                        Notification::make()
                            ->title('Upit je označen kao završen')
                            ->body('Status je postavljen na "Replied".')
                            ->success()
                            ->send();
                    }),

                Actions\EditAction::make(),
            ];
        }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Tabs::make('InquiryTabs')
                    ->tabs([
                        Tab::make('Detalji upita')
                            ->schema([
                                Grid::make()
                                    ->columns(2)
                                    ->schema([
                                        // LEVO: originalni upit
                                        Section::make('Originalni upit')
                                            ->schema([
                                                Grid::make()
                                                    ->columns(2)
                                                    ->schema([
                                                        TextEntry::make('guest_name')
                                                            ->label('Ime gosta')
                                                            ->default('-'),

                                                        TextEntry::make('guest_email')
                                                            ->label('Email')
                                                            ->default('-'),

                                                        TextEntry::make('guest_phone')
                                                            ->label('Telefon')
                                                            ->default('-'),

                                                        TextEntry::make('source')
                                                            ->label('Izvor')
                                                            ->formatStateUsing(fn ($state) => match ($state) {
                                                                'email'     => 'Email',
                                                                'web_form'  => 'Web forma',
                                                                'manual'    => 'Ručni unos',
                                                                default     => $state ?? '-',
                                                            }),
                                                    ]),

                                                TextEntry::make('subject')
                                                    ->label('Naslov / subject')
                                                    ->columnSpanFull()
                                                    ->default('-'),

                                                TextEntry::make('raw_message')
                                                    ->label('Tekst upita')
                                                    ->columnSpanFull()
                                                    ->default('')
                                                    ->markdown(),
                                            ])
                                            ->columns(1)
                                            ->columnSpan(1),

                                        // DESNO: AI polja + statusi
                                        Grid::make()
                                            ->columns(1)
                                            ->schema([
                                                Section::make('Ekstrahovani podaci (AI)')
                                                    ->schema([
                                                        Grid::make()
                                                            ->columns(2)
                                                            ->schema([
                                                                TextEntry::make('region')
                                                                    ->label('Regija')
                                                                    ->default('-'),

                                                                TextEntry::make('nights')
                                                                    ->label('Broj noćenja')
                                                                    ->default('-'),

                                                                TextEntry::make('date_from')
                                                                    ->label('Datum od')
                                                                    ->date(),

                                                                TextEntry::make('date_to')
                                                                    ->label('Datum do')
                                                                    ->date(),

                                                                TextEntry::make('adults')
                                                                    ->label('Odrasli')
                                                                    ->default('-'),

                                                                TextEntry::make('children')
                                                                    ->label('Deca')
                                                                    ->default('-'),

                                                                TextEntry::make('budget_min')
                                                                    ->label('Budžet min')
                                                                    ->formatStateUsing(fn ($state) => $state ? $state . ' €' : '-'),

                                                                TextEntry::make('budget_max')
                                                                    ->label('Budžet max')
                                                                    ->formatStateUsing(fn ($state) => $state ? $state . ' €' : '-'),
                                                            ]),
                                                    ])
                                                    ->columns(2),

                                                Section::make('Status i meta podaci')
                                                    ->schema([
                                                        Grid::make()
                                                            ->columns(2)
                                                            ->schema([
                                                                TextEntry::make('status')
                                                                    ->label('Status obrade')
                                                                    ->badge()
                                                                    ->color(fn ($state) => match ($state) {
                                                                        'new'       => 'gray',
                                                                        'extracted' => 'warning',
                                                                        'suggested' => 'info',
                                                                        'replied'   => 'success',
                                                                        'closed'    => 'danger',
                                                                        default     => 'gray',
                                                                    })
                                                                    ->formatStateUsing(fn ($state) => match ($state) {
                                                                        'new'       => 'New',
                                                                        'extracted' => 'Extracted',
                                                                        'suggested' => 'Suggested',
                                                                        'replied'   => 'Replied',
                                                                        'closed'    => 'Closed',
                                                                        default     => $state ?? '-',
                                                                    }),

                                                                TextEntry::make('reply_mode')
                                                                    ->label('Način odgovora')
                                                                    ->badge()
                                                                    ->color(fn ($state) => $state === 'ai_draft' ? 'primary' : 'secondary')
                                                                    ->formatStateUsing(fn ($state) => $state === 'ai_draft' ? 'AI draft' : 'Ručni'),

                                                                TextEntry::make('is_priority')
                                                                    ->label('Prioritet')
                                                                    ->formatStateUsing(fn ($state) => $state ? 'Da' : 'Ne'),

                                                                TextEntry::make('received_at')
                                                                    ->label('Primljeno')
                                                                    ->dateTime('d.m.Y H:i'),

                                                                TextEntry::make('processed_at')
                                                                    ->label('Obrađeno')
                                                                    ->dateTime('d.m.Y H:i'),

                                                                TextEntry::make('created_at')
                                                                    ->label('Kreirano')
                                                                    ->dateTime('d.m.Y H:i'),
                                                            ]),
                                                    ]),
                                            ])
                                            ->columnSpan(1),
                                    ]),
                            ]),

                        Tab::make('Predlozi smeštaja')
                            ->schema([
                                ViewEntry::make('suggestions')
                                    ->view('filament.inquiries.suggestions'),
                            ]),
                        Tab::make('AI draft odgovor')
                            ->schema([
                                TextEntry::make('ai_draft')
                                    ->label('Predlog odgovora')
                                    ->default('-')
                                    ->columnSpanFull()
                                    ->markdown(), // ili ->copyable() ako hoćeš ikonicu za copy
                            ]),
                    ])
                    ->persistTabInQueryString()
            ]);
    }
}
