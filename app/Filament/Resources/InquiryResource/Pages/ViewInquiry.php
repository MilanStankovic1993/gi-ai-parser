<?php

namespace App\Filament\Resources\InquiryResource\Pages;

use App\Filament\Resources\InquiryResource;
use App\Models\Inquiry;
use App\Services\InquiryAiExtractor;
use App\Services\InquiryAccommodationMatcher;
use App\Services\InquiryMissingData;
use App\Services\InquiryOfferDraftBuilder;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Tabs;
use Filament\Infolists\Components\Tabs\Tab;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Arr;
use App\Mail\InquiryDraftMail;
use Illuminate\Support\Facades\Mail;
use Filament\Forms\Components\Hidden;


class ViewInquiry extends ViewRecord
{
    protected static string $resource = InquiryResource::class;

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('send_reply')
                ->label('Send')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn () =>
                    filled($this->record->guest_email) &&
                    filled($this->record->ai_draft) &&
                    in_array($this->record->status, ['extracted', 'suggested'], true)
                )
                ->fillForm(function () {
                    /** @var Inquiry $record */
                    $record = $this->record;

                    $inbox = str_contains((string) $record->source, 'email:info') ? 'info' : 'booking';

                    return [
                        'inbox'   => $inbox,
                        'to'      => $record->guest_email,
                        'subject' => $record->subject ?: 'Upit',
                        'body'    => $record->ai_draft,
                    ];
                })
                ->form([
                    Hidden::make('inbox'),

                    TextInput::make('to')
                        ->label('To')
                        ->disabled()
                        ->dehydrated(false),

                    TextInput::make('subject')
                        ->label('Subject')
                        ->required()
                        ->maxLength(255),

                    Textarea::make('body')
                        ->label('Message')
                        ->rows(16)
                        ->required(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data) {
                    /** @var Inquiry $record */
                    $record = $this->record;

                    $inbox = $data['inbox'] ?? 'booking';

                    // 1) upiši eventualne izmene iz modala u ai_draft (da bude 1:1 šta je poslato)
                    $record->subject  = $data['subject'] ?? $record->subject;
                    $record->ai_draft = $data['body'] ?? $record->ai_draft;
                    $record->save();

                    // 2) odaberi SMTP creds + FROM po inboxu
                    if ($inbox === 'info') {
                        $smtpUser = env('SMTP_INFO_USERNAME');
                        $smtpPass = env('SMTP_INFO_PASSWORD');
                        $fromAddr = env('SMTP_INFO_FROM_ADDRESS', 'info@grckainfo.com');
                        $fromName = env('SMTP_INFO_FROM_NAME', 'GrckaInfo tim');
                    } else {
                        $smtpUser = env('SMTP_BOOKING_USERNAME');
                        $smtpPass = env('SMTP_BOOKING_PASSWORD');
                        $fromAddr = env('SMTP_BOOKING_FROM_ADDRESS', 'booking@grckainfo.com');
                        $fromName = env('SMTP_BOOKING_FROM_NAME', 'GrckaInfo tim');
                    }

                    if (blank($smtpUser) || blank($smtpPass) || blank($fromAddr)) {
                        Notification::make()
                            ->title('Nedostaju SMTP podaci')
                            ->body('Popuni SMTP_* u .env za ovaj inbox (username/password/from).')
                            ->danger()
                            ->send();

                        return;
                    }

                    // 3) runtime mail config (da šalje kao izabrani inbox)
                    config([
                        'mail.mailers.smtp.host'       => env('MAIL_HOST'),
                        'mail.mailers.smtp.port'       => (int) env('MAIL_PORT', 587),
                        'mail.mailers.smtp.encryption' => env('MAIL_ENCRYPTION', 'tls'),
                        'mail.mailers.smtp.username'   => $smtpUser,
                        'mail.mailers.smtp.password'   => $smtpPass,
                    ]);

                    // 4) pošalji
                    try {
                        Mail::to($record->guest_email)->send(
                            new InquiryDraftMail($record, $fromAddr, $fromName)
                        );
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Slanje nije uspelo')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return; // ✅ NE menjamo status
                    }

                    // ✅ Tek posle uspešnog slanja:
                    $record->status = 'replied';
                    $record->processed_at = now();
                    $record->save();

                    Notification::make()
                        ->title('Mejl je poslat')
                        ->success()
                        ->send();
                }),
           
            // ✅ QUICK EDIT (modal)
            Actions\Action::make('quick_edit')
                ->label('Quick edit')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->fillForm(fn () => $this->prefillQuickEditForm($this->record))
                ->form([
                    TextInput::make('region')->label('Regija')->maxLength(255),
                    TextInput::make('location')->label('Mesto')->maxLength(255),
                    TextInput::make('month_hint')->label('Okvirni period')->maxLength(255),

                    DatePicker::make('date_from')->label('Datum od'),
                    DatePicker::make('date_to')->label('Datum do'),

                    TextInput::make('nights')->label('Broj noćenja')->numeric()->minValue(0),
                    TextInput::make('adults')->label('Odrasli')->numeric()->minValue(0),

                    TextInput::make('children')->label('Deca')->numeric()->minValue(0),

                    TextInput::make('children_ages')
                        ->label('Uzrast dece (npr: 5 ili 5, 8)')
                        ->helperText('Ako ima dece, uzrast je obavezan da bismo dali ponudu (1:1 zahtev).')
                        ->maxLength(255),

                    TextInput::make('budget_min')->label('Budžet min (€)')->numeric()->minValue(0),
                    TextInput::make('budget_max')->label('Budžet max (€)')->numeric()->minValue(0),

                    Toggle::make('wants_near_beach')->label('Blizu plaže'),
                    Toggle::make('wants_parking')->label('Parking'),
                    Toggle::make('wants_quiet')->label('Mirna lokacija'),
                    Toggle::make('wants_pets')->label('Ljubimci'),
                    Toggle::make('wants_pool')->label('Bazen'),

                    Textarea::make('special_requirements')
                        ->label('Napomena / dodatni zahtevi')
                        ->rows(3),

                    Select::make('reply_mode')
                        ->label('Način odgovora')
                        ->options([
                            'ai_draft' => 'AI draft',
                            'manual'   => 'Ručni',
                        ])
                        ->required(),

                    Select::make('status')
                        ->label('Status')
                        ->options([
                            'new'       => 'New',
                            'extracted' => 'Extracted',
                            'suggested' => 'Suggested',
                            'replied'   => 'Replied',
                            'closed'    => 'Closed',
                        ])
                        ->required(),
                ])
                ->action(function (array $data) {
                    /** @var Inquiry $record */
                    $record = $this->record;

                    $data['children_ages'] = $this->normalizeChildrenAges($data['children_ages'] ?? null);

                    $children = isset($data['children']) ? (int) $data['children'] : null;
                    if ($children !== null && $children > 0 && empty($data['children_ages'])) {
                        Notification::make()
                            ->title('Nedostaje uzrast dece')
                            ->body('Po zahtevu: ako ima dece, uzrast je potreban da bismo dali ponudu.')
                            ->warning()
                            ->send();
                    }

                    $record->fill(Arr::only($data, [
                        'region','location','month_hint',
                        'date_from','date_to','nights',
                        'adults','children','children_ages',
                        'budget_min','budget_max',
                        'wants_near_beach','wants_parking','wants_quiet','wants_pets','wants_pool',
                        'special_requirements',
                        'reply_mode','status',
                    ]));

                    $record->processed_at = now();
                    $record->save();

                    $missing = InquiryMissingData::detect($record);

                    Notification::make()
                        ->title('Sačuvano')
                        ->body(empty($missing)
                            ? 'Sada ima dovoljno podataka za generisanje ponude.'
                            : 'I dalje nedostaje: ' . implode('; ', $missing)
                        )
                        ->success()
                        ->send();
                }),
            // RUN EXTRACTION
            Actions\Action::make('run_ai_extraction')
                ->label('Run AI extraction')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->requiresConfirmation()
                ->action(function () {
                    /** @var Inquiry $record */
                    $record = $this->record;

                    $extractor = app(InquiryAiExtractor::class);
                    $data = $extractor->extract($record);

                    $record->region     = $data['region']     ?? $record->region;
                    $record->location   = $data['location']   ?? $record->location;
                    $record->month_hint = $data['month_hint'] ?? $record->month_hint;

                    $record->date_from  = $data['date_from']  ?? $record->date_from;
                    $record->date_to    = $data['date_to']    ?? $record->date_to;
                    $record->nights     = $data['nights']     ?? $record->nights;

                    $adults   = $data['adults'] ?? null;
                    $children = $data['children'] ?? null;

                    if (is_array($adults))   $adults = count($adults);
                    if (is_array($children)) $children = count($children);

                    if (is_string($adults)) {
                        $adults = (int) preg_replace('/\D+/', '', $adults) ?: null;
                    }
                    if (is_string($children)) {
                        $children = (int) preg_replace('/\D+/', '', $children) ?: null;
                    }

                    if (is_int($adults)) {
                        $record->adults = $adults;
                    }
                    if (is_int($children)) {
                        $record->children = $children;
                    }

                    if (array_key_exists('children_ages', $data)) {
                        $record->children_ages = $this->normalizeChildrenAges($data['children_ages']);
                    }

                    $record->budget_min = $data['budget_min'] ?? $record->budget_min;
                    $record->budget_max = $data['budget_max'] ?? $record->budget_max;

                    $record->wants_near_beach = $data['wants_near_beach'] ?? $record->wants_near_beach;
                    $record->wants_parking    = $data['wants_parking'] ?? $record->wants_parking;
                    $record->wants_quiet      = $data['wants_quiet'] ?? $record->wants_quiet;
                    $record->wants_pets       = $data['wants_pets'] ?? $record->wants_pets;
                    $record->wants_pool       = $data['wants_pool'] ?? $record->wants_pool;

                    $record->special_requirements = $data['special_requirements'] ?? $record->special_requirements;

                    $record->language = $data['language'] ?? $record->language ?? 'sr';
                    $record->extraction_mode = $data['_mode'] ?? $record->extraction_mode ?? 'fallback';
                    $record->extraction_debug = json_encode($data, JSON_UNESCAPED_UNICODE);

                    $record->status = 'extracted';
                    $record->processed_at = now();
                    $record->save();

                    Notification::make()
                        ->title('Extraction je uspešno izvršena')
                        ->body($record->extraction_mode === 'ai'
                            ? 'Korišćen je AI extractor.'
                            : 'Korišćen je lokalni parser (fallback).'
                        )
                        ->success()
                        ->send();
                }),

            // GENERATE DRAFT
            Actions\Action::make('generate_ai_draft')
                ->label('Generate AI draft')
                ->icon('heroicon-o-envelope')
                ->color('success')
                ->requiresConfirmation()
                ->action(function () {
                    /** @var Inquiry $record */
                    $record = $this->record;

                    $missing = InquiryMissingData::detect($record);

                    if (! empty($missing)) {
                        $record->ai_draft = view('ai.templates.missing-info', [
                            'missing' => $missing,
                        ])->render();

                        $record->status = 'extracted';
                        $record->processed_at = now();
                        $record->save();

                        Notification::make()
                            ->title('Nedostaju ključni podaci')
                            ->body('Kreiran je draft sa pitanjima za dopunu (bez ponude).')
                            ->warning()
                            ->send();

                        return;
                    }

                    $matcher = app(InquiryAccommodationMatcher::class);
                    $candidates = $matcher->match($record, 5);

                    if ($candidates->isEmpty()) {
                        Notification::make()
                            ->title('Nema kandidata za ovaj upit')
                            ->body('Proveri provizijske objekte i cenovne periode za traženi period.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $builder = app(InquiryOfferDraftBuilder::class);
                    $draft   = $builder->build($record, $candidates);

                    $record->ai_draft = $draft;
                    $record->status   = 'suggested';
                    $record->processed_at = now();
                    $record->save();

                    Notification::make()
                        ->title('Draft je generisan')
                        ->body('Spreman za pregled i eventualne izmene.')
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
                    /** @var Inquiry $record */
                    $record = $this->record;

                    $record->status = 'replied';
                    $record->processed_at = now();
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
        return $infolist->schema([
            Tabs::make('InquiryTabs')
                ->tabs([
                    Tab::make('Detalji upita')
                        ->schema([
                            Grid::make()
                                ->columns([
                                    'default' => 1,
                                    'lg'      => 2,
                                ])
                                ->schema([
                                    // LEFT
                                    Section::make('Originalni upit')
                                        ->schema([
                                            Grid::make()
                                                ->columns(2)
                                                ->schema([
                                                    TextEntry::make('guest_name')->label('Ime gosta')->default('-'),
                                                    TextEntry::make('guest_email')->label('Email')->default('-'),
                                                    TextEntry::make('guest_phone')->label('Telefon')->default('-'),
                                                    TextEntry::make('source')
                                                        ->label('Izvor')
                                                        ->formatStateUsing(fn ($state) => match ($state) {
                                                            'email'     => 'Email',
                                                            'web_form'  => 'Web forma',
                                                            'manual'    => 'Ručni unos',
                                                            default     => $state ?? '-',
                                                        }),
                                                ]),
                                            TextEntry::make('subject')->label('Naslov / subject')->columnSpanFull()->default('-'),
                                            TextEntry::make('raw_message')->label('Tekst upita')->columnSpanFull()->default('')->markdown(),
                                        ])
                                        ->columns(1)
                                        ->columnSpan(1),

                                    // RIGHT
                                    Grid::make()
                                        ->columns(1)
                                        ->schema([
                                            // Big card
                                            Section::make('Ekstrahovani podaci (AI)')
                                                ->schema([
                                                    Grid::make()
                                                        ->columns(2)
                                                        ->schema([
                                                            TextEntry::make('region')->label('Regija')->default('-'),
                                                            TextEntry::make('location')->label('Mesto')->default('-'),
                                                            TextEntry::make('month_hint')->label('Okvirni period')->default('-'),

                                                            TextEntry::make('nights')->label('Broj noćenja')->default('-'),
                                                            TextEntry::make('date_from')->label('Datum od')->date(),
                                                            TextEntry::make('date_to')->label('Datum do')->date(),

                                                            TextEntry::make('adults')->label('Odrasli')->default('-'),
                                                            TextEntry::make('children')->label('Deca')->default('-'),

                                                            TextEntry::make('children_ages')
                                                                ->label('Uzrast dece')
                                                                ->formatStateUsing(fn ($state) => is_string($state) && trim($state) !== '' ? $state : '-'),

                                                            TextEntry::make('budget_min')
                                                                ->label('Budžet min')
                                                                ->formatStateUsing(fn ($state) => $state ? $state . ' €' : '-'),

                                                            TextEntry::make('budget_max')
                                                                ->label('Budžet max')
                                                                ->formatStateUsing(fn ($state) => $state ? $state . ' €' : '-'),
                                                        ]),
                                                ])
                                                ->columns(2),

                                            // ✅ 3 cards in a row (desktop), stacked on mobile
                                            Grid::make()
                                                ->columns([
                                                    'default' => 1,
                                                    'lg'      => 3,
                                                ])
                                                ->schema([
                                                    Section::make('Želje gosta')
                                                        ->schema([
                                                            Grid::make()
                                                                ->columns(3)
                                                                ->schema([
                                                                    TextEntry::make('wants_near_beach')->label('Blizu plaže')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                                    TextEntry::make('wants_parking')->label('Parking')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                                    TextEntry::make('wants_quiet')->label('Mirna lokacija')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                                    TextEntry::make('wants_pets')->label('Ljubimci')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                                    TextEntry::make('wants_pool')->label('Bazen')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                                ]),
                                                        ]),

                                                    Section::make('Extraction (prod meta)')
                                                        ->schema([
                                                            Grid::make()
                                                                ->columns(2)
                                                                ->schema([
                                                                    TextEntry::make('extraction_mode')->label('Mode')->default('-'),
                                                                    TextEntry::make('language')->label('Jezik')->default('-'),
                                                                ]),
                                                            TextEntry::make('special_requirements')
                                                                ->label('Napomena / dodatni zahtevi')
                                                                ->default('-')
                                                                ->columnSpanFull(),
                                                        ]),

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

                                                                    TextEntry::make('is_priority')->label('Prioritet')->formatStateUsing(fn ($state) => $state ? 'Da' : 'Ne'),

                                                                    TextEntry::make('received_at')->label('Primljeno')->dateTime('d.m.Y H:i'),
                                                                    TextEntry::make('processed_at')->label('Obrađeno')->dateTime('d.m.Y H:i'),
                                                                    TextEntry::make('created_at')->label('Kreirano')->dateTime('d.m.Y H:i'),
                                                                ]),
                                                        ]),
                                                ]),
                                        ])
                                        ->columnSpan(1),
                                ]),
                        ]),

                    Tab::make('Predlozi smeštaja')
                        ->schema([
                            ViewEntry::make('suggestions')->view('filament.inquiries.suggestions'),
                        ]),

                    Tab::make('AI draft odgovor')
                        ->schema([
                            TextEntry::make('ai_draft')
                                ->label('Predlog odgovora')
                                ->default('-')
                                ->columnSpanFull()
                                ->markdown(),
                        ]),
                ])
                ->persistTabInQueryString(),
        ]);
    }

    private function prefillQuickEditForm(Inquiry $i): array
    {
        return [
            'region' => $i->region,
            'location' => $i->location,
            'month_hint' => $i->month_hint,
            'date_from' => $i->date_from,
            'date_to' => $i->date_to,
            'nights' => $i->nights,
            'adults' => $i->adults,
            'children' => $i->children,
            'children_ages' => $i->children_ages,
            'budget_min' => $i->budget_min,
            'budget_max' => $i->budget_max,
            'wants_near_beach' => $i->wants_near_beach,
            'wants_parking' => $i->wants_parking,
            'wants_quiet' => $i->wants_quiet,
            'wants_pets' => $i->wants_pets,
            'wants_pool' => $i->wants_pool,
            'special_requirements' => $i->special_requirements,
            'reply_mode' => $i->reply_mode,
            'status' => $i->status,
        ];
    }

    private function normalizeChildrenAges($value): ?string
    {
        if ($value === null) return null;

        if (is_array($value)) {
            $nums = array_values(array_filter(array_map(function ($v) {
                $n = preg_replace('/\D+/', '', (string) $v);
                return $n === '' ? null : (int) $n;
            }, $value)));
            return count($nums) ? implode(', ', $nums) : null;
        }

        $state = trim((string) $value);
        if ($state === '') return null;

        $parts = preg_split('/[,\s;]+/', $state);
        $nums = [];
        foreach ($parts as $p) {
            $n = preg_replace('/\D+/', '', (string) $p);
            if ($n !== '') $nums[] = (int) $n;
        }

        $nums = array_values(array_unique(array_filter($nums, fn ($n) => $n >= 0 && $n <= 17)));

        return count($nums) ? implode(', ', $nums) : null;
    }
}
