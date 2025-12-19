<?php

namespace App\Filament\Resources\InquiryResource\Pages;

use App\Filament\Resources\InquiryResource;
use App\Mail\InquiryDraftMail;
use App\Models\Inquiry;
use App\Services\InquiryAccommodationMatcher;
use App\Services\InquiryAiExtractor;
use App\Services\InquiryMissingData;
use App\Services\InquiryOfferDraftBuilder;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
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
use Illuminate\Support\Facades\Mail;

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
                    in_array($this->record->status, ['needs_info', 'extracted', 'suggested'], true)
                )
                ->fillForm(function () {
                    /** @var Inquiry $record */
                    $record = $this->record;

                    $src = (string) ($record->source ?? '');
                    $inbox = str_contains($src, 'info') ? 'info' : 'booking';

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

                    $record->subject  = $data['subject'] ?? $record->subject;
                    $record->ai_draft = $data['body'] ?? $record->ai_draft;
                    $record->save();

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

                    config([
                        'mail.mailers.smtp.host'       => env('MAIL_HOST'),
                        'mail.mailers.smtp.port'       => (int) env('MAIL_PORT', 587),
                        'mail.mailers.smtp.encryption' => env('MAIL_ENCRYPTION', 'tls'),
                        'mail.mailers.smtp.username'   => $smtpUser,
                        'mail.mailers.smtp.password'   => $smtpPass,
                    ]);

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

                        return;
                    }

                    $record->status = 'replied';
                    $record->processed_at = now();
                    $record->save();

                    Notification::make()
                        ->title('Mejl je poslat')
                        ->success()
                        ->send();
                }),

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

                    Textarea::make('special_requirements')->label('Napomena / dodatni zahtevi')->rows(3),

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
                            'new'        => 'New',
                            'needs_info' => 'Needs info',
                            'extracted'  => 'Extracted',
                            'suggested'  => 'Suggested',
                            'replied'    => 'Replied',
                            'closed'     => 'Closed',
                            'no_ai'      => 'Bez AI obrade',
                        ])
                        ->required(),
                ])
                ->action(function (array $data) {
                    /** @var Inquiry $record */
                    $record = $this->record;

                    $data['children_ages'] = InquiryMissingData::normalizeChildrenAges($data['children_ages'] ?? null);

                    $children = isset($data['children']) ? (int) $data['children'] : null;
                    if ($children !== null && $children > 0 && empty($data['children_ages'])) {
                        Notification::make()
                            ->title('Nedostaje uzrast dece')
                            ->body('Po zahtevu: ako ima dece, uzrast je potreban da bismo dali ponudu.')
                            ->warning()
                            ->send();

                        return;
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

                    $this->record = Inquiry::query()
                        ->with('aiInquiry')
                        ->findOrFail($record->getKey());

                    $missing = InquiryMissingData::detect($this->record);

                    Notification::make()
                        ->title('Sačuvano')
                        ->body(empty($missing)
                            ? 'Sada ima dovoljno podataka za generisanje ponude.'
                            : 'I dalje nedostaje: ' . implode('; ', $missing)
                        )
                        ->success()
                        ->send();
                }),

            Actions\Action::make('run_ai_extraction')
                ->label('Run extraction')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->requiresConfirmation()
                ->action(function () {
                    /** @var Inquiry $record */
                    $record = Inquiry::query()
                        ->with('aiInquiry')
                        ->findOrFail($this->record->getKey());

                    // ✅ sinhronizuj Livewire/Filament record
                    $this->record = $record;

                    $extractor = app(InquiryAiExtractor::class);
                    $data = $extractor->extract($record);

                    // --- fill basic extracted fields (ne briši postojeće ako AI vrati null) ---
                    foreach (['region','location','month_hint'] as $k) {
                        if (array_key_exists($k, $data) && filled($data[$k])) {
                            $record->{$k} = $data[$k];
                        }
                    }

                    if (!empty($data['date_from'])) {
                        $record->date_from = $data['date_from'];
                    }
                    if (!empty($data['date_to'])) {
                        $record->date_to = $data['date_to'];
                    }
                    if (array_key_exists('nights', $data) && $data['nights'] !== null) {
                        $record->nights = (int) $data['nights'];
                    }

                    // --- adults / children ---
                    $adults   = $data['adults']   ?? null;
                    $children = $data['children'] ?? null;

                    if (is_array($adults))   $adults = count($adults);
                    if (is_array($children)) $children = count($children);

                    if (is_string($adults))   $adults = (int) preg_replace('/\D+/', '', $adults) ?: null;
                    if (is_string($children)) $children = (int) preg_replace('/\D+/', '', $children) ?: null;

                    if (is_int($adults))   $record->adults = $adults;
                    if (is_int($children)) $record->children = $children;

                    // --- children ages (uvek normalizuj ako je ključ prisutan) ---
                    if (array_key_exists('children_ages', $data)) {
                        $record->children_ages = InquiryMissingData::normalizeChildrenAges($data['children_ages']);
                    }

                    // --- budget ---
                    foreach (['budget_min','budget_max'] as $k) {
                        if (array_key_exists($k, $data) && $data[$k] !== null) {
                            $record->{$k} = $data[$k];
                        }
                    }

                    // --- wants ---
                    foreach (['wants_near_beach','wants_parking','wants_quiet','wants_pets','wants_pool'] as $k) {
                        if (array_key_exists($k, $data) && $data[$k] !== null) {
                            $record->{$k} = (bool) $data[$k];
                        }
                    }

                    if (array_key_exists('special_requirements', $data) && filled($data['special_requirements'])) {
                        $record->special_requirements = $data['special_requirements'];
                    }

                    $record->language = $data['language'] ?? $record->language ?? 'sr';
                    $record->extraction_mode  = $data['_mode'] ?? $record->extraction_mode ?? 'fallback';
                    $record->extraction_debug = $data;

                    // ✅ KLJUČNO: deterministički izračun date_to ako imamo date_from + nights
                    if ($record->date_from && $record->nights && ! $record->date_to) {
                        try {
                            $record->date_to = Carbon::parse($record->date_from)
                                ->addDays((int) $record->nights)
                                ->toDateString();
                        } catch (\Throwable) {
                            // ignore
                        }
                    }

                    // ✅ Statusi: izračunaj missing i sync-uj i Inquiry i AiInquiry
                    $missing = InquiryMissingData::detect($record);

                    $record->status = empty($missing) ? 'extracted' : Inquiry::STATUS_NEEDS_INFO;
                    $record->processed_at = now();
                    $record->save();

                    // sync ai_inquiries
                    $record->loadMissing('aiInquiry');
                    if ($record->aiInquiry) {
                        $record->aiInquiry->missing_fields = empty($missing) ? null : $missing;
                        $record->aiInquiry->status = empty($missing) ? 'parsed' : 'needs_info';
                        $record->aiInquiry->parsed_at = now();
                        $record->aiInquiry->save();
                    }

                    Notification::make()
                        ->title('Extraction je uspešno izvršena')
                        ->body($record->extraction_mode === 'ai'
                            ? 'Korišćen je AI extractor.'
                            : 'Korišćen je lokalni parser (fallback).'
                        )
                        ->success()
                        ->send();
                }),

            Actions\Action::make('generate_ai_draft')
                ->label('Generate draft')
                ->icon('heroicon-o-envelope')
                ->color('success')
                ->requiresConfirmation()
                ->action(function () {
                    /** @var Inquiry $record */
                    $record = Inquiry::query()
                        ->with('aiInquiry')
                        ->findOrFail($this->record->getKey());

                    // ✅ sinhronizuj Livewire/Filament record
                    $this->record = $record;

                    $missing = InquiryMissingData::detect($record);

                    // 1) Ako fale ključni podaci -> template za dopunu (bez ponude)
                    if (! empty($missing)) {
                        $record->ai_draft = view('ai.templates.missing-info', [
                            'missing' => $missing,
                        ])->render();

                        $record->status = Inquiry::STATUS_NEEDS_INFO;
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

                    // 2) primary+alternatives+log
                    $out = $matcher->matchWithAlternatives($record, 5, 5);

                    $primary = collect($out['primary'] ?? []);
                    $alts    = collect($out['alternatives'] ?? []);
                    $log     = $out['log'] ?? [];

                    // snimi payload na ai_inquiries
                    $record->loadMissing('aiInquiry');
                    if ($record->aiInquiry) {
                        $record->aiInquiry->suggestions_payload = [
                            'primary'      => $primary->take(5)->values()->all(),
                            'alternatives' => $alts->take(5)->values()->all(),
                            'log'          => $log,
                        ];
                        $record->aiInquiry->save();
                    }

                    // helper: candidates -> template items (title/details/price/url)
                    $toTemplateItems = function ($candidates) use ($record) {
                        return collect($candidates)->take(5)->map(function ($c) use ($record) {
                            $hotel = data_get($c, 'hotel');
                            $room  = data_get($c, 'room');

                            $title =
                                data_get($c, 'name')
                                ?? data_get($c, 'title')
                                ?? data_get($hotel, 'hotel_title')
                                ?? data_get($hotel, 'title')
                                ?? 'Smeštaj';

                            // direktan link ka stranici na sajtu
                            $url = data_get($c, 'url')
                                ?? data_get($hotel, 'url')
                                ?? null;

                            if ($url && ! str_starts_with($url, 'http')) {
                                $url = 'https://' . ltrim($url, '/');
                            }

                            // cena za period (ako je imamo)
                            $total = data_get($c, 'price.total');
                            $price = $total
                                ? number_format((float) $total, 0, ',', '.') . ' €'
                                : null;

                            $details = collect([
                                // tip (ako postoji u bazi; prilagodi polja po potrebi)
                                data_get($room, 'room_type') ? 'Tip: ' . data_get($room, 'room_type') : null,

                                // kapacitet
                                (data_get($room, 'room_adults') || data_get($room, 'room_children'))
                                    ? 'Kapacitet: do ' . ((int) data_get($room, 'room_adults', 0) + (int) data_get($room, 'room_children', 0)) . ' osobe'
                                    : null,

                                // plaža (ako imaš neko polje u hotelu – prilagodi)
                                data_get($hotel, 'beach_distance') ? 'Plaža: ' . data_get($hotel, 'beach_distance') : null,
                            ])->filter()->implode(' • ');

                            return [
                                'title'   => $title,
                                'details' => $details ?: null,
                                'price'   => $price,
                                'url'     => $url,
                            ];
                        })->values()->all();
                    };

                    // 3) Ako NEMA primary, ali IMA alternatives -> šaljemo alternatives (traženi fallback)
                    if ($primary->isEmpty() && $alts->isNotEmpty()) {

                        $record->ai_draft = view('ai.templates.no-primary-with-alternatives', [
                            'guest'        => trim((string) ($record->guest_name ?? '')),
                            'alternatives' => $toTemplateItems($alts),
                        ])->render();

                        $record->status = 'suggested';
                        $record->processed_at = now();
                        $record->save();

                        Notification::make()
                            ->title('Nema ponude po traženim kriterijumima')
                            ->body('Generisan je odgovor sa alternativnim predlozima smeštaja.')
                            ->warning()
                            ->send();

                        return;
                    }

                    // 4) Ako NEMA ni primary ni alternatives -> probaj fallback kroz matcher (isti matcher, ne random lista)
                    if ($primary->isEmpty() && $alts->isEmpty()) {

                        $fallbackCandidates = $matcher->findFallbackAlternatives($record, 5);

                        if ($fallbackCandidates->isNotEmpty()) {
                            $record->ai_draft = view('ai.templates.no-primary-with-alternatives', [
                                'guest'        => trim((string) ($record->guest_name ?? '')),
                                'alternatives' => $toTemplateItems($fallbackCandidates),
                            ])->render();

                            $record->status = 'suggested';
                            $record->processed_at = now();
                            $record->save();

                            Notification::make()
                                ->title('Nema ponude po kriterijumima')
                                ->body('Generisan je odgovor sa 4–5 alternativnih predloga.')
                                ->warning()
                                ->send();

                            return;
                        }

                        // baš ništa nije nađeno → informativni draft
                        $record->ai_draft = view('ai.templates.missing-info', [
                            'missing' => [
                                'Nažalost trenutno nemamo odgovarajuću ponudu u bazi po traženim kriterijumima. Da li ste fleksibilni za drugu lokaciju / datum (±2–3 dana) ili budžet, kako bismo poslali 4–5 alternativnih predloga?',
                            ],
                        ])->render();

                        $record->status = 'extracted';
                        $record->processed_at = now();
                        $record->save();

                        Notification::make()
                            ->title('Nema kandidata')
                            ->body('Kreiran je informativni draft.')
                            ->warning()
                            ->send();

                        return;
                    }

                    // 5) Ima primary -> standardni draft sa ponudama
                    $builder = app(InquiryOfferDraftBuilder::class);
                    $draft   = $builder->build($record, $primary);

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
                                            TextEntry::make('raw_message')->label('Tekst upita')->columnSpanFull()->default('-'),
                                        ])
                                        ->columns(1)
                                        ->columnSpan(1),

                                    Grid::make()
                                        ->columns(1)
                                        ->schema([
                                            Section::make('Ekstrahovani podaci')
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
                                                                ->formatStateUsing(function ($state) {
                                                                    if (is_array($state)) {
                                                                        return count($state) ? implode(', ', $state) : '-';
                                                                    }
                                                                    return is_string($state) && trim($state) !== '' ? $state : '-';
                                                                }),

                                                            TextEntry::make('budget_min')->label('Budžet min')->formatStateUsing(fn ($state) => $state ? $state . ' €' : '-'),
                                                            TextEntry::make('budget_max')->label('Budžet max')->formatStateUsing(fn ($state) => $state ? $state . ' €' : '-'),
                                                        ]),
                                                ]),

                                            Grid::make()
                                                ->columns([
                                                    'default' => 1,
                                                    'lg'      => 3,
                                                ])
                                                ->schema([
                                                    Section::make('Želje gosta')
                                                        ->schema([
                                                            Grid::make()->columns(3)->schema([
                                                                TextEntry::make('wants_near_beach')->label('Blizu plaže')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                                TextEntry::make('wants_parking')->label('Parking')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                                TextEntry::make('wants_quiet')->label('Mirna')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                                TextEntry::make('wants_pets')->label('Ljubimci')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                                TextEntry::make('wants_pool')->label('Bazen')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                            ]),
                                                        ]),

                                                    Section::make('Extraction meta')
                                                        ->schema([
                                                            Grid::make()->columns(2)->schema([
                                                                TextEntry::make('extraction_mode')->label('Mode')->default('-'),
                                                                TextEntry::make('language')->label('Jezik')->default('-'),
                                                            ]),
                                                            TextEntry::make('special_requirements')->label('Napomena')->default('-')->columnSpanFull(),
                                                        ]),

                                                    Section::make('Status i meta')
                                                        ->schema([
                                                            Grid::make()->columns(2)->schema([
                                                                TextEntry::make('status')
                                                                    ->label('Status')
                                                                    ->badge()
                                                                    ->color(fn ($state) => match ($state) {
                                                                        'new'        => 'gray',
                                                                        'needs_info' => 'warning',
                                                                        'extracted'  => 'warning',
                                                                        'suggested'  => 'info',
                                                                        'replied'    => 'success',
                                                                        'no_ai'      => 'gray',
                                                                        'closed'     => 'danger',
                                                                        default      => 'gray',
                                                                    }),
                                                                TextEntry::make('reply_mode')
                                                                    ->label('Odgovor')
                                                                    ->badge()
                                                                    ->color(fn ($state) => $state === 'ai_draft' ? 'primary' : 'secondary')
                                                                    ->formatStateUsing(fn ($state) => $state === 'ai_draft' ? 'AI draft' : 'Ručni'),

                                                                TextEntry::make('is_priority')->label('Prioritet')->formatStateUsing(fn ($state) => $state ? 'Da' : 'Ne'),
                                                                TextEntry::make('received_at')->label('Primljeno')->dateTime('d.m.Y H:i'),
                                                                TextEntry::make('processed_at')->label('Obrađeno')->dateTime('d.m.Y H:i'),
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
            'children_ages' => is_array($i->children_ages) ? implode(', ', $i->children_ages) : $i->children_ages,
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
}
