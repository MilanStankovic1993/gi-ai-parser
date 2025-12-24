<?php

namespace App\Filament\Resources\InquiryResource\Pages;

use App\Filament\Resources\InquiryResource;
use App\Mail\InquiryDraftMail;
use App\Models\AiInquiry;
use App\Models\Inquiry;
use App\Services\InquiryAccommodationMatcher;
use App\Services\InquiryAiExtractor;
use App\Services\InquiryMissingData;
use App\Services\InquiryOfferDraftBuilder;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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

    /**
     * Uvek učitaj aiInquiry da infolist tabovi vide JSON.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record = Inquiry::query()
            ->with('aiInquiry')
            ->findOrFail($this->record->getKey());
    }

    private function refreshRecord(): void
    {
        $this->record = Inquiry::query()
            ->with('aiInquiry')
            ->findOrFail($this->record->getKey());

        $this->dispatch('$refresh');
    }

    private function groupsCount(Inquiry $r): int
    {
        $groups = data_get($r, 'party.groups', []);
        if (is_string($groups)) {
            $decoded = json_decode($groups, true);
            $groups = is_array($decoded) ? $decoded : [];
        }
        return is_array($groups) ? count($groups) : 0;
    }

    private function isSingle(Inquiry $r): bool
    {
        return $this->groupsCount($r) <= 1;
    }

    private function isMulti(Inquiry $r): bool
    {
        return $this->groupsCount($r) >= 2;
    }

    protected function getHeaderActions(): array
    {
        $triStateOptions = [
            ''  => '-',
            '1' => 'Da',
            '0' => 'Ne',
        ];

        return [
            Actions\Action::make('send_reply')
                ->label('Send')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn () =>
                    filled($this->record->guest_email) &&
                    filled($this->record->ai_draft) &&
                    in_array((string) $this->record->status, ['needs_info', 'extracted', 'suggested', 'no_ai'], true)
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

                    $this->refreshRecord();

                    Notification::make()
                        ->title('Mejl je poslat')
                        ->success()
                        ->send();
                }),

            // ✅ QUICK EDIT (SINGLE)
            Actions\Action::make('quick_edit')
                ->label('Quick edit')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->visible(fn () => $this->isSingle($this->record))
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

                    Select::make('wants_near_beach')->label('Blizu plaže')->options($triStateOptions)->native(false),
                    Select::make('wants_parking')->label('Parking')->options($triStateOptions)->native(false),
                    Select::make('wants_quiet')->label('Mirna lokacija')->options($triStateOptions)->native(false),
                    Select::make('wants_pets')->label('Ljubimci')->options($triStateOptions)->native(false),
                    Select::make('wants_pool')->label('Bazen')->options($triStateOptions)->native(false),

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

                    // ✅ normalize children_ages (NE unique, 1-17)
                    $data['children_ages'] = InquiryMissingData::normalizeChildrenAges($data['children_ages'] ?? null);

                    // tri-state selects
                    foreach (['wants_near_beach','wants_parking','wants_quiet','wants_pets','wants_pool'] as $k) {
                        if (! array_key_exists($k, $data)) continue;
                        $v = $data[$k];
                        if ($v === '' || $v === null) $data[$k] = null;
                        elseif ((string) $v === '1') $data[$k] = true;
                        elseif ((string) $v === '0') $data[$k] = false;
                    }

                    // kids gate: ako children > 0, mora ages
                    $children = isset($data['children']) ? (int) $data['children'] : null;
                    if ($children !== null && $children > 0 && empty($data['children_ages'])) {
                        Notification::make()
                            ->title('Nedostaje uzrast dece')
                            ->body('Ako ima dece, uzrast je potreban da bismo dali ponudu.')
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

                    // ✅ canonical party.groups (single)
                    $party = is_array($record->party) ? $record->party : [];
                    $party['groups'] = [[
                        'adults'        => (int) ($record->adults ?? 0),
                        'children'      => (int) ($record->children ?? 0),
                        'children_ages' => is_array($record->children_ages)
                            ? array_values($record->children_ages)
                            : (InquiryMissingData::normalizeChildrenAges($record->children_ages) ?? []),
                        'requirements'  => [],
                    ]];
                    $record->party = $party;

                    $record->processed_at = now();
                    $record->save();

                    $this->refreshRecord();

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

            // ✅ EDIT GROUPS (MULTI)
            Actions\Action::make('edit_groups')
                ->label('Edit groups')
                ->icon('heroicon-o-user-group')
                ->color('warning')
                ->visible(fn () => $this->isMulti($this->record))
                ->fillForm(function () {
                    $groups = data_get($this->record, 'party.groups', []);
                    if (is_string($groups)) {
                        $decoded = json_decode($groups, true);
                        $groups = is_array($decoded) ? $decoded : [];
                    }
                    $groups = is_array($groups) ? $groups : [];

                    $groups = collect($groups)->map(function ($g) {
                        $g = is_array($g) ? $g : [];
                        $ages = data_get($g, 'children_ages', []);
                        if (is_array($ages)) $g['children_ages'] = implode(', ', $ages);
                        $req = data_get($g, 'requirements', []);
                        if (is_array($req)) $g['requirements'] = implode("\n", $req);
                        return $g;
                    })->values()->all();

                    return ['groups' => $groups];
                })
                ->form([
                    Repeater::make('groups')
                        ->label('Porodice')
                        ->schema([
                            TextInput::make('adults')->numeric()->minValue(0)->required(),
                            TextInput::make('children')->numeric()->minValue(0)->default(0),

                            TextInput::make('children_ages')
                                ->label('Uzrast dece (npr: 8, 5)')
                                ->helperText('Ako children > 0, obavezno upisati sve uzraste.'),

                            Textarea::make('requirements')
                                ->label('Zahtevi (po liniji)')
                                ->rows(3)
                                ->helperText('Npr: odvojena soba, mirno, parking...'),
                        ])
                        ->columns(2)
                        ->minItems(2)
                        ->reorderable(false),
                ])
                ->action(function (array $data) {
                    /** @var Inquiry $record */
                    $record = $this->record;

                    $groups = $data['groups'] ?? [];
                    $groups = is_array($groups) ? $groups : [];

                    $groups = collect($groups)->map(function ($g) {
                        $g = is_array($g) ? $g : [];
                        $g['adults'] = (int) ($g['adults'] ?? 0);
                        $g['children'] = (int) ($g['children'] ?? 0);

                        $g['children_ages'] = InquiryMissingData::normalizeChildrenAges($g['children_ages'] ?? null) ?? [];

                        $lines = preg_split("/\r\n|\n|\r/", (string) ($g['requirements'] ?? '')) ?: [];
                        $g['requirements'] = array_values(array_filter(array_map('trim', $lines)));

                        return $g;
                    })->values()->all();

                    $party = is_array($record->party) ? $record->party : [];
                    $party['groups'] = $groups;
                    $record->party = $party;

                    // legacy totals
                    $record->adults = collect($groups)->sum('adults');
                    $record->children = collect($groups)->sum('children');
                    $record->children_ages = collect($groups)->pluck('children_ages')->flatten()->values()->all();

                    $record->processed_at = now();
                    $record->save();

                    $this->refreshRecord();

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

                    $this->record = $record;

                    $extractor = app(InquiryAiExtractor::class);
                    $data = $extractor->extract($record);

                    $entities   = is_array($data['entities'] ?? null) ? $data['entities'] : null;
                    $travelTime = is_array($data['travel_time'] ?? null) ? $data['travel_time'] : null;

                    $party     = is_array($data['party'] ?? null) ? $data['party'] : [];
                    $units     = is_array($data['units'] ?? null) ? $data['units'] : [];
                    $wishes    = is_array($data['wishes'] ?? null) ? $data['wishes'] : [];
                    $questions = is_array($data['questions'] ?? null) ? $data['questions'] : [];
                    $tags      = is_array($data['tags'] ?? null) ? $data['tags'] : [];
                    $why       = is_array($data['why_no_offer'] ?? null) ? $data['why_no_offer'] : [];

                    // 1) canonical json fields
                    $record->intent = $data['intent'] ?? $record->intent;

                    // entities: ako extractor ne vrati, napravi minimalno
                    $record->entities = $entities ?: [
                        'property_candidates' => is_array($data['property_candidates'] ?? null) ? $data['property_candidates'] : [],
                        'location_candidates' => is_array($data['location_candidates'] ?? null) ? $data['location_candidates'] : [],
                        'region_candidates'   => is_array($data['region_candidates'] ?? null) ? $data['region_candidates'] : [],
                        'date_candidates'     => is_array($data['date_candidates'] ?? null) ? $data['date_candidates'] : [],
                    ];

                    // ✅ travel_time: NE gubi date_window
                    $record->travel_time = $travelTime ?: [
                        'month_hint'    => $data['month_hint'] ?? ($record->month_hint ?? null),
                        'date_from'     => $data['date_from'] ?? ($record->date_from ? $record->date_from->toDateString() : null),
                        'date_to'       => $data['date_to']   ?? ($record->date_to ? $record->date_to->toDateString() : null),
                        'nights'        => $data['nights'] ?? $record->nights,
                        'date_window'   => data_get($data, 'date_window') ?? data_get($data, 'travel_time.date_window'),
                        'date_candidates' => is_array($data['date_candidates'] ?? null) ? $data['date_candidates'] : [],
                    ];

                    $record->party        = $party;
                    $record->units        = $units; // debug only (ok za F1)
                    $record->wishes       = $wishes;
                    $record->questions    = $questions;
                    $record->tags         = $tags;
                    $record->why_no_offer = $why;

                    // 2) summary fields – ne gazi praznim
                    foreach (['region','location','month_hint'] as $k) {
                        if (array_key_exists($k, $data) && filled($data[$k])) {
                            $record->{$k} = $data[$k];
                        }
                    }

                    if (! empty($data['date_from'])) $record->date_from = $data['date_from'];
                    if (! empty($data['date_to']))   $record->date_to   = $data['date_to'];

                    if (array_key_exists('nights', $data) && $data['nights'] !== null) $record->nights = (int) $data['nights'];
                    if (array_key_exists('adults', $data) && $data['adults'] !== null) $record->adults = (int) $data['adults'];
                    if (array_key_exists('children', $data) && $data['children'] !== null) $record->children = (int) $data['children'];

                    if (array_key_exists('children_ages', $data)) {
                        $record->children_ages = InquiryMissingData::normalizeChildrenAges($data['children_ages']);
                    }

                    foreach (['budget_min','budget_max'] as $k) {
                        if (array_key_exists($k, $data) && $data[$k] !== null) $record->{$k} = (int) $data[$k];
                    }

                    foreach (['wants_near_beach','wants_parking','wants_quiet','wants_pets','wants_pool'] as $k) {
                        if (array_key_exists($k, $data) && $data[$k] !== null) $record->{$k} = (bool) $data[$k];
                    }

                    if (array_key_exists('special_requirements', $data) && filled($data['special_requirements'])) {
                        $record->special_requirements = $data['special_requirements'];
                    }

                    $record->language         = $data['language'] ?? ($record->language ?? 'sr');
                    $record->extraction_mode  = $data['_mode'] ?? ($record->extraction_mode ?? 'fallback');
                    $record->extraction_debug = $data;

                    // 3) deterministički date_to
                    if ($record->date_from && $record->nights && ! $record->date_to) {
                        try {
                            $record->date_to = Carbon::parse($record->date_from)
                                ->addDays((int) $record->nights)
                                ->toDateString();
                        } catch (\Throwable) {}
                    }

                    // 4) CANONICALIZE party.groups (najbitnije)
                    $party = is_array($record->party) ? $record->party : [];
                    $groups = data_get($party, 'groups', []);

                    if (is_string($groups)) {
                        $decoded = json_decode($groups, true);
                        $groups = is_array($decoded) ? $decoded : [];
                    }

                    $units = is_array($record->units) ? $record->units : [];

                    // ako nema groups, a ima units => napravi groups iz units[*].party_group
                    if ((! is_array($groups) || count($groups) === 0) && count($units) > 0) {
                        $groups = collect($units)->map(function ($u) {
                            $g = data_get($u, 'party_group', []);
                            $g = is_array($g) ? $g : [];

                            $g['adults'] = (int) data_get($g, 'adults', 0);
                            $g['children'] = (int) data_get($g, 'children', 0);
                            $g['children_ages'] = InquiryMissingData::normalizeChildrenAges(data_get($g, 'children_ages')) ?? [];
                            $req = data_get($g, 'requirements', []);
                            $g['requirements'] = is_array($req) ? array_values(array_filter(array_map('trim', $req))) : [];

                            return $g;
                        })->values()->all();
                    }

                    // ako i dalje nema groups => napravi single group iz legacy
                    if (! is_array($groups) || count($groups) === 0) {
                        $groups = [[
                            'adults'        => (int) ($record->adults ?? 0),
                            'children'      => (int) ($record->children ?? 0),
                            'children_ages' => InquiryMissingData::normalizeChildrenAges($record->children_ages) ?? [],
                            'requirements'  => [],
                        ]];
                    }

                    $groups = collect($groups)->map(function ($g) {
                        $g = is_array($g) ? $g : [];
                        $g['adults'] = (int) ($g['adults'] ?? 0);
                        $g['children'] = (int) ($g['children'] ?? 0);
                        $g['children_ages'] = InquiryMissingData::normalizeChildrenAges($g['children_ages'] ?? null) ?? [];
                        $req = $g['requirements'] ?? [];
                        $g['requirements'] = is_array($req) ? array_values(array_filter(array_map('trim', $req))) : [];
                        return $g;
                    })->values()->all();

                    $party['groups'] = $groups;
                    $record->party = $party;

                    // legacy totals
                    $record->adults = collect($groups)->sum('adults');
                    $record->children = collect($groups)->sum('children');
                    $record->children_ages = collect($groups)->pluck('children_ages')->flatten()->values()->all();

                    // 5) status
                    $intent = (string) ($record->intent ?? 'unknown');
                    $isOutOfScope = in_array($intent, ['owner_request','long_stay_private','spam'], true);

                    $missing = InquiryMissingData::detect($record);

                    if ($isOutOfScope) {
                        $record->status = Inquiry::STATUS_NO_AI;
                        $record->ai_draft = $record->ai_draft ?: "Poštovani,\n\nHvala na poruci. Ovaj tip upita nije u dometu standardne ponude (out-of-scope: {$intent}).\n\nSrdačan pozdrav,\nGrckaInfo tim";
                    } else {
                        $record->status = empty($missing) ? Inquiry::STATUS_EXTRACTED : Inquiry::STATUS_NEEDS_INFO;
                    }

                    $record->processed_at = now();
                    $record->save();

                    // 6) sync ai_inquiries audit
                    $record->loadMissing('aiInquiry');
                    if ($record->aiInquiry) {
                        /** @var AiInquiry $ai */
                        $ai = $record->aiInquiry;

                        $ai->intent = $record->intent;
                        $ai->out_of_scope_reason = $data['out_of_scope_reason'] ?? null;

                        $ai->parsed_payload = Arr::except($data, ['_mode', '_warnings']);
                        $ai->parse_warnings = $data['_warnings'] ?? [];

                        $ai->missing_fields = empty($missing) ? null : $missing;
                        $ai->status = $isOutOfScope
                            ? AiInquiry::STATUS_NO_AI
                            : (empty($missing) ? AiInquiry::STATUS_PARSED : AiInquiry::STATUS_NEEDS_INFO);

                        $ai->parsed_at = now();
                        $ai->save();
                    }

                    $this->refreshRecord();

                    Notification::make()
                        ->title('Extraction je uspešno izvršena')
                        ->body(($record->extraction_mode ?? '') === 'ai' ? 'Korišćen je AI extractor.' : 'Korišćen je lokalni parser (fallback).')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('generate_ai_draft')
                ->label('Generate draft')
                ->icon('heroicon-o-envelope')
                ->color('success')
                ->requiresConfirmation()
                ->action(fn () => $this->generateAiDraft()),

            Actions\Action::make('mark_as_replied')
                ->label('Mark as replied')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => (string) $this->record->status !== 'replied')
                ->requiresConfirmation()
                ->action(function () {
                    /** @var Inquiry $record */
                    $record = $this->record;

                    $record->status = 'replied';
                    $record->processed_at = now();
                    $record->save();

                    $this->refreshRecord();

                    Notification::make()
                        ->title('Upit je označen kao završen')
                        ->body('Status je postavljen na "Replied".')
                        ->success()
                        ->send();
                }),

            Actions\EditAction::make(),
        ];
    }

    private function generateAiDraft(): void
    {
        /** @var Inquiry $record */
        $record = Inquiry::query()
            ->with('aiInquiry')
            ->findOrFail($this->record->getKey());

        $this->record = $record;

        // ✅ debug marker (vidi da li se stvarno regeneriše)
        // $marker = 'GENERATED_AT: ' . now()->format('Y-m-d H:i:s');

        $intent = (string) ($record->intent ?? 'unknown');

        // OUT OF SCOPE → ✅ UVEK overwrite (bez ?:)
        if (in_array($intent, ['owner_request', 'long_stay_private', 'spam'], true)) {
            $record->ai_draft = "Poštovani,\n\nHvala na poruci. Ovaj tip upita trenutno ne obrađujemo automatski (intent: {$intent}).\n\nSrdačan pozdrav,\nGrckaInfo tim";

            $record->status = Inquiry::STATUS_NO_AI;
            $record->processed_at = now();
            $record->save();

            $this->refreshRecord();

            Notification::make()
                ->title('Out-of-scope')
                ->body('Generisan je informativni draft (bez ponude).')
                ->warning()
                ->send();

            return;
        }

        $missing = InquiryMissingData::detect($record);

        if (! empty($missing)) {
            $record->ai_draft = view('ai.templates.missing-info', [
                'missing' => $missing,
            ])->render();

            $record->status = Inquiry::STATUS_NEEDS_INFO;
            $record->processed_at = now();
            $record->save();

            $this->refreshRecord();

            Notification::make()
                ->title('Nedostaju ključni podaci')
                ->body('Kreiran je draft sa pitanjima za dopunu (bez ponude).')
                ->warning()
                ->send();

            return;
        }

        $matcher = app(InquiryAccommodationMatcher::class);
        $out = $matcher->matchWithAlternatives($record, 5, 5);

        $primary = collect($out['primary'] ?? []);
        $alts    = collect($out['alternatives'] ?? []);
        $log     = $out['log'] ?? [];

        $record->loadMissing('aiInquiry');
        if ($record->aiInquiry) {
            $record->aiInquiry->suggestions_payload = [
                'primary'      => $primary->take(5)->values()->all(),
                'alternatives' => $alts->take(5)->values()->all(),
                'log'          => $log,
            ];
            $record->aiInquiry->suggested_at = now();
            $record->aiInquiry->save();
        }

        $toTemplateItems = function ($candidates) {
            return collect($candidates)->take(5)->map(function ($c) {
                $hotel = data_get($c, 'hotel');
                $title =
                    data_get($c, 'title')
                    ?? data_get($hotel, 'hotel_title')
                    ?? data_get($hotel, 'title')
                    ?? 'Smeštaj';

                $url = data_get($hotel, 'public_url')
                    ?? data_get($hotel, 'link')
                    ?? data_get($c, 'url')
                    ?? null;

                $total = data_get($c, 'price.total');
                $price = $total ? number_format((float) $total, 0, ',', '.') . ' €' : null;

                return [
                    'title'   => $title,
                    'details' => null,
                    'price'   => $price,
                    'url'     => $url,
                ];
            })->values()->all();
        };

        if ($primary->isEmpty() && $alts->isNotEmpty()) {
            $record->ai_draft = view('ai.templates.no-primary-with-alternatives', [
                'guest'        => trim((string) ($record->guest_name ?? '')),
                'alternatives' => $toTemplateItems($alts),
            ])->render();

            $record->status = Inquiry::STATUS_SUGGESTED;
            $record->processed_at = now();
            $record->save();

            $this->refreshRecord();

            Notification::make()
                ->title('Nema ponude po traženim kriterijumima')
                ->body('Generisan je odgovor sa alternativnim predlozima smeštaja.')
                ->warning()
                ->send();

            return;
        }

        if ($primary->isEmpty() && $alts->isEmpty()) {
            $record->ai_draft = view('ai.templates.missing-info', [
                'missing' => [
                    'Trenutno nemamo odgovarajuću ponudu u bazi po traženim kriterijumima. Da li ste fleksibilni za drugu lokaciju / datum (±2–3 dana) ili budžet?',
                ],
            ])->render();

            $record->status = Inquiry::STATUS_EXTRACTED;
            $record->processed_at = now();
            $record->save();

            $this->refreshRecord();

            Notification::make()
                ->title('Nema kandidata')
                ->body('Kreiran je informativni draft.')
                ->warning()
                ->send();

            return;
        }

        $builder = app(InquiryOfferDraftBuilder::class);
        $draft   = $builder->build($record, $primary);

        // ✅ marker + overwrite
        $record->ai_draft = $draft;
        $record->status   = Inquiry::STATUS_SUGGESTED;
        $record->processed_at = now();
        $record->save();

        $this->refreshRecord();

        Notification::make()
            ->title('Draft je generisan')
            ->body('Spreman za pregled i eventualne izmene.')
            ->success()
            ->send();
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
                                                    TextEntry::make('source')->label('Izvor')->default('-'),
                                                ]),
                                            TextEntry::make('subject')->label('Naslov / subject')->columnSpanFull()->default('-'),
                                            TextEntry::make('raw_message')->label('Tekst upita')->columnSpanFull()->default('-'),
                                        ])
                                        ->columns(1)
                                        ->columnSpan(1),

                                    Grid::make()
                                        ->columns(1)
                                        ->schema([
                                            Section::make('Ekstrahovani podaci (summary)')
                                                ->schema([
                                                    TextEntry::make('groups_summary')
                                                        ->label('Porodice / grupe')
                                                        ->state(fn (Inquiry $record) => $this->formatGroupsSummary($record))
                                                        ->extraAttributes(['style' => 'white-space: pre-wrap;'])
                                                        ->columnSpanFull()
                                                        ->visible(fn (Inquiry $record) => $this->isMulti($record)),

                                                    Grid::make()
                                                        ->columns(2)
                                                        ->schema([
                                                            TextEntry::make('intent')->label('Intent')->default('-'),
                                                            TextEntry::make('region')->label('Regija')->default('-'),
                                                            TextEntry::make('location')->label('Mesto')->default('-'),
                                                            TextEntry::make('month_hint')->label('Okvirni period')->default('-'),

                                                            TextEntry::make('nights')->label('Broj noćenja')->default('-'),
                                                            TextEntry::make('date_from')->label('Datum od')->date(),
                                                            TextEntry::make('date_to')->label('Datum do')->date(),

                                                            TextEntry::make('adults')->label('Odrasli')->default('-')
                                                                ->visible(fn (Inquiry $record) => $this->isSingle($record)),
                                                            TextEntry::make('children')->label('Deca')->default('-')
                                                                ->visible(fn (Inquiry $record) => $this->isSingle($record)),

                                                            TextEntry::make('children_ages')
                                                                ->label('Uzrast dece')
                                                                ->visible(fn (Inquiry $record) => $this->isSingle($record))
                                                                ->formatStateUsing(function ($state) {
                                                                    if (is_array($state)) return count($state) ? implode(', ', $state) : '-';
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

                                                    Section::make('Extraction meta')
                                                        ->schema([
                                                            Grid::make()->columns(2)->schema([
                                                                TextEntry::make('extraction_mode')->label('Mode')->default('-'),
                                                                TextEntry::make('language')->label('Jezik')->default('-'),
                                                            ]),
                                                            TextEntry::make('special_requirements')->label('Napomena')->default('-')->columnSpanFull(),
                                                        ]),
                                                ]),

                                            // ✅ za F1: samo AI audit (koristan) + osnovno; izbacili smo "Sirovi JSON (inquiries)" jer komplikuje i smara
                                            Section::make('AI Audit (ai_inquiries)')
                                                ->collapsible()
                                                ->collapsed()
                                                ->schema([
                                                    TextEntry::make('ai_status')->label('ai_inquiries.status')->state(fn (Inquiry $record) => (string) ($record->aiInquiry?->status ?? '-')),
                                                    TextEntry::make('ai_intent')->label('ai_inquiries.intent')->state(fn (Inquiry $record) => (string) ($record->aiInquiry?->intent ?? '-')),
                                                    TextEntry::make('ai_missing_fields')->label('ai_inquiries.missing_fields')->state(fn (Inquiry $record) => $this->prettyJson($record->aiInquiry?->missing_fields))->columnSpanFull()->prose()->copyable(),
                                                    TextEntry::make('ai_suggestions_payload')->label('ai_inquiries.suggestions_payload')->state(fn (Inquiry $record) => $this->prettyJson($record->aiInquiry?->suggestions_payload))->columnSpanFull()->prose()->copyable(),
                                                    Grid::make()->columns(2)->schema([
                                                        TextEntry::make('ai_parsed_at')->label('ai_inquiries.parsed_at')->state(fn (Inquiry $record) => optional($record->aiInquiry?->parsed_at)->format('d.m.Y H:i') ?: '-'),
                                                        TextEntry::make('ai_suggested_at')->label('ai_inquiries.suggested_at')->state(fn (Inquiry $record) => optional($record->aiInquiry?->suggested_at)->format('d.m.Y H:i') ?: '-'),
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

    private function formatGroupsSummary(Inquiry $record): ?string
    {
        $groups = data_get($record, 'party.groups', []);
        if (is_string($groups)) {
            $decoded = json_decode($groups, true);
            $groups = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($groups) || count($groups) < 2) return null;

        return collect($groups)->map(function ($g, $i) {
            $a = (int) data_get($g, 'adults', 0);
            $c = (int) data_get($g, 'children', 0);

            $ages = data_get($g, 'children_ages', []);
            $ages = is_array($ages) ? array_values($ages) : [];
            $agesTxt = $c > 0 ? (count($ages) ? implode(', ', $ages) : '—') : '—';

            $req = data_get($g, 'requirements', []);
            $req = is_array($req) ? array_filter(array_map('trim', $req)) : [];
            $reqTxt = count($req) ? (' | ' . implode(' / ', $req)) : '';

            return 'Porodica ' . ($i + 1) . ": {$a} odraslih, {$c} dece (uzrast: {$agesTxt}){$reqTxt}";
        })->implode("\n");
    }

    private function prefillQuickEditForm(Inquiry $i): array
    {
        $toSelect = fn ($v) => $v === null ? '' : ($v ? '1' : '0');

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

            'wants_near_beach' => $toSelect($i->wants_near_beach),
            'wants_parking' => $toSelect($i->wants_parking),
            'wants_quiet' => $toSelect($i->wants_quiet),
            'wants_pets' => $toSelect($i->wants_pets),
            'wants_pool' => $toSelect($i->wants_pool),

            'special_requirements' => $i->special_requirements,
            'reply_mode' => $i->reply_mode,
            'status' => $i->status,
        ];
    }

    private function prettyJson($state): string
    {
        if ($state === null) return '-';

        if (is_string($state)) {
            $trim = trim($state);
            if ($trim === '') return '-';

            $decoded = json_decode($trim, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: $trim;
            }

            return $trim;
        }

        if (is_array($state) || is_object($state)) {
            return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '-';
        }

        return (string) $state;
    }
}
