<?php

namespace App\Filament\Resources\InquiryResource\Pages;

use App\Filament\Resources\InquiryResource;
use App\Mail\InquiryDraftMail;
use App\Models\AiInquiry;
use App\Models\Inquiry;
use App\Models\Grcka\Hotel as GrckaHotel;
use App\Models\Grcka\Location as GrckaLocation;
use App\Models\Grcka\Region as GrckaRegion;
use App\Services\InquiryAccommodationMatcher;
use App\Services\InquiryAiExtractor;
use App\Services\InquiryMissingData;
use App\Services\InquiryOfferDraftBuilder;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid as FormGrid;
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

    /**
     * Normalizacija whitespace (ubija "rupe" od praznih linija / CRLF)
     */
    private function normalizeText($value): string
    {
        $s = (string) ($value ?? '');

        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace("/[ \t]+\n/", "\n", $s) ?? $s;
        $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;

        return trim($s);
    }

    /**
     * SMTP config read via config() (works with config:cache)
     */
    private function resolveSmtpForInbox(string $inbox): array
    {
        $inbox = $inbox === 'info' ? 'info' : 'booking';

        $mailer = $inbox === 'info' ? 'smtp_info' : 'smtp_booking';

        $username = config("mail.mailers.$mailer.username");
        $password = config("mail.mailers.$mailer.password");

        if ($inbox === 'info') {
            $fromAddr = config('mail.inboxes.info.from.address')
                ?: (config('mail.from.address') ?: 'info@grckainfo.com');
            $fromName = config('mail.inboxes.info.from.name')
                ?: (config('mail.from.name') ?: 'GrckaInfo tim');
        } else {
            $fromAddr = config('mail.inboxes.booking.from.address')
                ?: (config('mail.from.address') ?: 'booking@grckainfo.com');
            $fromName = config('mail.inboxes.booking.from.name')
                ?: (config('mail.from.name') ?: 'GrckaInfo tim');
        }

        return [
            'mailer'   => $mailer,
            'username' => $username,
            'password' => $password,
            'fromAddr' => $fromAddr,
            'fromName' => $fromName,
        ];
    }

    /**
     * Helper: pokušaj da iz teksta (Inquiry.region) pogodi region_id
     * - prvo exact, pa like fallback
     */
    private function guessRegionIdFromText(?string $text): ?int
    {
        $text = trim((string) $text);
        if ($text === '') return null;

        return GrckaRegion::query()
            ->where('region', $text)
            ->value('region_id')
            ?? GrckaRegion::query()
                ->where('region', 'like', '%' . $text . '%')
                ->value('region_id');
    }

    /**
     * Helper: pokušaj da iz teksta (Inquiry.location) pogodi location.id (po nazivu),
     * opcionalno suzi na region_id.
     * - prvo exact, pa like fallback
     */
    private function guessLocationIdFromText(?string $text, ?int $regionId = null): ?int
    {
        $text = trim((string) $text);
        if ($text === '') return null;

        return GrckaLocation::query()
            ->when($regionId, fn ($q) => $q->where('region_id', $regionId))
            ->where('location', $text)
            ->value('id')
            ?? GrckaLocation::query()
                ->when($regionId, fn ($q) => $q->where('region_id', $regionId))
                ->where('location', 'like', '%' . $text . '%')
                ->value('id');
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
                    in_array((string) $this->record->status, ['new', 'needs_info', 'extracted', 'suggested', 'no_ai'], true)
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

                    $inbox = ($data['inbox'] ?? 'booking') === 'info' ? 'info' : 'booking';

                    $record->subject  = $data['subject'] ?? $record->subject;
                    $record->ai_draft = $data['body'] ?? $record->ai_draft;
                    $record->save();

                    $smtp = $this->resolveSmtpForInbox($inbox);

                    if (blank($smtp['username']) || blank($smtp['password']) || blank($smtp['fromAddr'])) {
                        Notification::make()
                            ->title('Nedostaju SMTP podaci')
                            ->body('Popuni SMTP_* u .env za ovaj inbox (username/password/from) i uradi config:cache na serveru.')
                            ->danger()
                            ->send();
                        return;
                    }

                    try {
                        Mail::mailer($smtp['mailer'])
                            ->to($record->guest_email)
                            ->send(new InquiryDraftMail($record, $smtp['fromAddr'], $smtp['fromName']));
                    } catch (\Throwable $e) {

                        if ($smtp['mailer'] === 'smtp_booking') {
                            try {
                                $fallback = $this->resolveSmtpForInbox('info');

                                if (blank($fallback['username']) || blank($fallback['password']) || blank($fallback['fromAddr'])) {
                                    Notification::make()
                                        ->title('Slanje nije uspelo')
                                        ->body($e->getMessage())
                                        ->danger()
                                        ->send();
                                    return;
                                }

                                Mail::mailer($fallback['mailer'])
                                    ->to($record->guest_email)
                                    ->send(new InquiryDraftMail($record, $fallback['fromAddr'], $fallback['fromName']));

                                Notification::make()
                                    ->title('Mejl je poslat (fallback)')
                                    ->body('Slanje preko booking inbox-a nije uspelo, poslato je preko info inbox-a.')
                                    ->warning()
                                    ->send();
                            } catch (\Throwable $e2) {
                                Notification::make()
                                    ->title('Slanje nije uspelo (ni fallback)')
                                    ->body($e2->getMessage())
                                    ->danger()
                                    ->send();
                                return;
                            }
                        } else {
                            Notification::make()
                                ->title('Slanje nije uspelo')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                            return;
                        }
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

            /**
             * JEDNA EDIT AKCIJA (single + multi)
             * + dropdown regija/mesto/hotel iz GRCKA baze sa live search
             * - NE MENJA matcher (i dalje se cuva tekst region/location)
             */
            Actions\Action::make('edit_search')
                ->label('Edit (search params)')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->visible(fn () => true)
                ->fillForm(fn () => $this->prefillEditSearchForm($this->record))
                ->form([
                    FormGrid::make()
                        ->columns(['default' => 1, 'lg' => 2])
                        ->schema([

                            // -------- REGIJA (pt_regions) --------
                            Select::make('region_id')
                                ->label('Regija (iz baze)')
                                ->searchable()
                                ->preload(false)
                                ->reactive()
                                ->placeholder('Kucaj regiju...')
                                ->getSearchResultsUsing(function (string $search): array {
                                    $search = trim($search);
                                    if ($search === '') return [];

                                    return GrckaRegion::query()
                                        ->where('region', 'like', "%{$search}%")
                                        ->orderBy('region')
                                        ->limit(50)
                                        ->pluck('region', 'region_id')
                                        ->toArray();
                                })
                                ->getOptionLabelUsing(fn ($value): ?string =>
                                    $value ? GrckaRegion::query()->where('region_id', $value)->value('region') : null
                                )
                                ->afterStateUpdated(function (callable $set) {
                                    // kad se promeni regija, resetuj mesto/hotel
                                    $set('location_id', null);
                                    $set('hotel_id', null);
                                }),

                            // -------- MESTO (pt_locations) --------
                            Select::make('location_id')
                                ->label('Mesto (iz baze)')
                                ->searchable()
                                ->preload(false)
                                ->reactive()
                                ->placeholder('Kucaj mesto...')
                                ->getSearchResultsUsing(function (callable $get, string $search): array {
                                    $search = trim($search);
                                    if ($search === '') return [];

                                    $regionId = $get('region_id');

                                    return GrckaLocation::query()
                                        ->when($regionId, fn ($q) => $q->where('region_id', $regionId))
                                        ->where('location', 'like', "%{$search}%")
                                        ->orderBy('location')
                                        ->limit(50)
                                        ->pluck('location', 'id')
                                        ->toArray();
                                })
                                ->getOptionLabelUsing(fn ($value): ?string =>
                                    $value ? GrckaLocation::query()->where('id', $value)->value('location') : null
                                )
                                ->afterStateUpdated(function (callable $set) {
                                    $set('hotel_id', null);
                                }),

                            // -------- HOTEL (pt_hotels) --------
                            Select::make('hotel_id')
                                ->label('Hotel (iz baze)')
                                ->searchable()
                                ->preload(false)
                                ->placeholder('Kucaj hotel...')
                                ->helperText('Pretraga je ograničena na AI-eligible hotele.')
                                ->getSearchResultsUsing(function (callable $get, string $search): array {
                                    $search = trim($search);
                                    if ($search === '') return [];

                                    $locationId = $get('location_id');
                                    $regionId   = $get('region_id');

                                    $q = GrckaHotel::query()
                                        ->aiEligible()
                                        // suzi po mestu (hotel_city je kod tebe string ID lokacije)
                                        ->when($locationId, fn ($qq) => $qq->where('hotel_city', (string) $locationId))
                                        // ako nema mesta, a ima region, koristi matchRegion(regionName)
                                        ->when(!$locationId && $regionId, function ($qq) use ($regionId) {
                                            $regionName = GrckaRegion::query()->where('region_id', $regionId)->value('region');
                                            if ($regionName) {
                                                $qq->matchRegion($regionName);
                                            }
                                        })
                                        // ✅ samo realne kolone
                                        ->where(function ($qq) use ($search) {
                                            $qq->where('hotel_title', 'like', "%{$search}%")
                                                ->orWhere('custom_name', 'like', "%{$search}%")
                                                ->orWhere('api_name', 'like', "%{$search}%")
                                                ->orWhere('hotel_slug', 'like', "%{$search}%");
                                        })
                                        ->limit(50);

                                    return $q->get(['hotel_id', 'hotel_title', 'custom_name', 'api_name'])
                                        ->mapWithKeys(function ($h) {
                                            $label = (string) ($h->hotel_title ?: $h->custom_name ?: $h->api_name ?: ('Hotel #' . $h->hotel_id));
                                            return [(int) $h->hotel_id => $label];
                                        })
                                        ->toArray();
                                })
                                ->getOptionLabelUsing(function ($value): ?string {
                                    if (! $value) return null;

                                    $h = GrckaHotel::query()
                                        ->where('hotel_id', (int) $value)
                                        ->first(['hotel_id', 'hotel_title', 'custom_name', 'api_name']);

                                    if (! $h) return null;

                                    return (string) ($h->hotel_title ?: $h->custom_name ?: $h->api_name ?: ('Hotel #' . $h->hotel_id));
                                }),

                            // ✅ tekst polja su fallback, ali disable-ovana kad postoji izbor iz baze (da ne dođe do konflikta)
                            TextInput::make('region')
                                ->label('Regija (tekst)')
                                ->maxLength(255)
                                ->disabled(fn (callable $get) => filled($get('region_id')))
                                ->helperText('Može ručno, ali ako izabereš regiju iz baze – ovo se zaključava.'),

                            TextInput::make('location')
                                ->label('Mesto (tekst)')
                                ->maxLength(255)
                                ->disabled(fn (callable $get) => filled($get('location_id')))
                                ->helperText('Može ručno, ali ako izabereš mesto iz baze – ovo se zaključava.'),

                            TextInput::make('month_hint')->label('Okvirni period')->maxLength(255),

                            DatePicker::make('date_from')->label('Datum od'),
                            DatePicker::make('date_to')->label('Datum do'),

                            TextInput::make('nights')->label('Broj noćenja')->numeric()->minValue(0),

                            TextInput::make('budget_min')->label('Budžet min (€)')->numeric()->minValue(0),
                            TextInput::make('budget_max')->label('Budžet max (€)')->numeric()->minValue(0),

                            Select::make('wants_near_beach')->label('Blizu plaže')->options($triStateOptions)->native(false),
                            Select::make('wants_parking')->label('Parking')->options($triStateOptions)->native(false),
                            Select::make('wants_quiet')->label('Mirna lokacija')->options($triStateOptions)->native(false),
                            Select::make('wants_pets')->label('Ljubimci')->options($triStateOptions)->native(false),
                            Select::make('wants_pool')->label('Bazen')->options($triStateOptions)->native(false),

                            Textarea::make('special_requirements')->label('Napomena / dodatni zahtevi')->rows(3)->columnSpanFull(),

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
                        ]),

                    Repeater::make('groups')
                        ->label('Porodice / grupe')
                        ->schema([
                            TextInput::make('adults')->label('Odrasli')->numeric()->minValue(0)->required(),
                            TextInput::make('children')->label('Deca')->numeric()->minValue(0)->default(0),

                            TextInput::make('children_ages')
                                ->label('Uzrast dece (npr: 8, 5)')
                                ->helperText('Ako children > 0, obavezno upisati sve uzraste.'),

                            Textarea::make('requirements')
                                ->label('Zahtevi (po liniji)')
                                ->rows(3)
                                ->helperText('Npr: odvojena soba, mirno, parking...'),
                        ])
                        ->columns(2)
                        ->minItems(1)
                        ->reorderable(false)
                        ->columnSpanFull(),
                ])
                ->action(fn (array $data) => $this->saveEditSearchForm($data)),

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
                    $party      = is_array($data['party'] ?? null) ? $data['party'] : [];
                    $units      = is_array($data['units'] ?? null) ? $data['units'] : [];
                    $wishes     = is_array($data['wishes'] ?? null) ? $data['wishes'] : [];
                    $questions  = is_array($data['questions'] ?? null) ? $data['questions'] : [];
                    $tags       = is_array($data['tags'] ?? null) ? $data['tags'] : [];
                    $why        = is_array($data['why_no_offer'] ?? null) ? $data['why_no_offer'] : [];
                    $locationJson = is_array($data['location_json'] ?? null) ? $data['location_json'] : null;

                    if (array_key_exists('intent', $data) && filled($data['intent'])) {
                        $record->intent = (string) $data['intent'];
                    }

                    $record->entities = $entities ?: [
                        'property_candidates' => is_array($data['property_candidates'] ?? null) ? $data['property_candidates'] : [],
                        'location_candidates' => is_array($data['location_candidates'] ?? null) ? $data['location_candidates'] : [],
                        'region_candidates'   => is_array($data['region_candidates'] ?? null) ? $data['region_candidates'] : [],
                        'date_candidates'     => is_array($data['date_candidates'] ?? null) ? $data['date_candidates'] : [],
                    ];

                    $record->travel_time = $travelTime ?: [
                        'date_from'   => $data['date_from'] ?? ($record->date_from ? $record->date_from->toDateString() : null),
                        'date_to'     => $data['date_to']   ?? ($record->date_to ? $record->date_to->toDateString() : null),
                        'date_window' => data_get($data, 'travel_time.date_window') ?? null,
                        'nights'      => $data['nights'] ?? $record->nights,
                    ];

                    if ($locationJson !== null) {
                        $record->location_json = $locationJson;
                    } elseif (! is_array($record->location_json)) {
                        $bestLoc = $data['location'] ?? $record->location ?? null;
                        $record->location_json = [
                            'primary' => filled($bestLoc) ? [['query' => (string) $bestLoc, 'confidence' => null]] : [],
                            'fallback' => [],
                            'notes' => null,
                        ];
                    }

                    $record->party        = $party;
                    $record->units        = $units;
                    $record->wishes       = $wishes;
                    $record->questions    = $questions;
                    $record->tags         = $tags;
                    $record->why_no_offer = $why;

                    foreach (['region','location','month_hint'] as $k) {
                        if (array_key_exists($k, $data) && filled($data[$k])) {
                            $record->{$k} = $data[$k];
                        }
                    }

                    $legacyDateFrom = $data['date_from'] ?? data_get($record->travel_time, 'date_from');
                    $legacyDateTo   = $data['date_to']   ?? data_get($record->travel_time, 'date_to');

                    if (! empty($legacyDateFrom)) $record->date_from = $legacyDateFrom;
                    if (! empty($legacyDateTo))   $record->date_to   = $legacyDateTo;

                    if (array_key_exists('nights', $data) && $data['nights'] !== null) {
                        $record->nights = (int) $data['nights'];
                    } elseif (data_get($record->travel_time, 'nights') !== null) {
                        $record->nights = (int) data_get($record->travel_time, 'nights');
                    }

                    if (array_key_exists('adults', $data) && $data['adults'] !== null) {
                        $record->adults = (int) $data['adults'];
                    } elseif (data_get($record->party, 'adults') !== null) {
                        $record->adults = (int) data_get($record->party, 'adults');
                    }

                    if (array_key_exists('children', $data) && $data['children'] !== null) {
                        $record->children = (int) $data['children'];
                    } elseif (data_get($record->party, 'children') !== null) {
                        $record->children = (int) data_get($record->party, 'children');
                    }

                    if (array_key_exists('children_ages', $data)) {
                        $record->children_ages = InquiryMissingData::normalizeChildrenAges($data['children_ages']);
                    } elseif (is_array(data_get($record->party, 'children_ages'))) {
                        $record->children_ages = array_values(data_get($record->party, 'children_ages'));
                    }

                    foreach (['budget_min','budget_max'] as $k) {
                        if (array_key_exists($k, $data) && $data[$k] !== null) $record->{$k} = (int) $data[$k];
                    }

                    foreach (['wants_near_beach','wants_parking','wants_quiet','wants_pets','wants_pool'] as $k) {
                        if (! array_key_exists($k, $data)) continue;

                        $v = $data[$k];
                        if ($v === null || $v === '') {
                            $record->{$k} = null;
                        } elseif ($v === true || $v === false) {
                            $record->{$k} = $v;
                        } elseif (is_numeric($v)) {
                            $record->{$k} = ((int) $v) === 1 ? true : (((int) $v) === 0 ? false : null);
                        } elseif (is_string($v)) {
                            $t = mb_strtolower(trim($v));
                            if (in_array($t, ['true','da','yes','1'], true)) $record->{$k} = true;
                            elseif (in_array($t, ['false','ne','no','0'], true)) $record->{$k} = false;
                        }
                    }

                    if (array_key_exists('special_requirements', $data) && filled($data['special_requirements'])) {
                        $record->special_requirements = $data['special_requirements'];
                    }

                    $record->language         = $data['language'] ?? ($record->language ?? 'sr');
                    $record->extraction_mode  = $data['_mode'] ?? ($record->extraction_mode ?? 'fallback');
                    $record->extraction_debug = $data;

                    if ($record->date_from && $record->nights && ! $record->date_to) {
                        try {
                            $record->date_to = Carbon::parse($record->date_from)
                                ->addDays((int) $record->nights)
                                ->toDateString();
                        } catch (\Throwable) {}
                    }

                    $party = is_array($record->party) ? $record->party : [];
                    $groups = data_get($party, 'groups', []);
                    if (is_string($groups)) {
                        $decoded = json_decode($groups, true);
                        $groups = is_array($decoded) ? $decoded : [];
                    }

                    $units = is_array($record->units) ? $record->units : [];

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

                    $record->adults = collect($groups)->sum('adults');
                    $record->children = collect($groups)->sum('children');
                    $record->children_ages = collect($groups)->pluck('children_ages')->flatten()->values()->all();

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

        $intent = (string) ($record->intent ?? 'unknown');

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

            $record->aiInquiry->missing_fields = null;
            $record->aiInquiry->status = \App\Models\AiInquiry::STATUS_SUGGESTED;

            $record->aiInquiry->save();
        }

        $toTemplateItems = function ($candidates) {
            return collect($candidates)->take(5)->map(function ($c) {
                $hotel = data_get($c, 'hotel');

                $title =
                    data_get($c, 'title')
                    ?? data_get($hotel, 'hotel_title')
                    ?? data_get($hotel, 'custom_name')
                    ?? data_get($hotel, 'api_name')
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
        $textAttrs = ['class' => 'whitespace-pre-wrap leading-relaxed'];

        return $infolist
            ->columns([
                'default' => 1,
                'lg'      => 1,
            ])
            ->schema([
                Tabs::make('InquiryTabs')
                    ->columnSpanFull()
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
                                                    ->columns([
                                                        'default' => 1,
                                                        'sm'      => 2,
                                                    ])
                                                    ->schema([
                                                        TextEntry::make('guest_name')->label('Ime gosta')->default('-'),
                                                        TextEntry::make('guest_email')->label('Email')->default('-'),
                                                        TextEntry::make('guest_phone')->label('Telefon')->default('-'),
                                                        TextEntry::make('source')->label('Izvor')->default('-'),
                                                    ]),

                                                TextEntry::make('subject')
                                                    ->label('Naslov / subject')
                                                    ->columnSpanFull()
                                                    ->default('-'),

                                                TextEntry::make('raw_message')
                                                    ->label('Tekst upita')
                                                    ->columnSpanFull()
                                                    ->default('-')
                                                    ->extraAttributes($textAttrs)
                                                    ->formatStateUsing(fn ($state) => $this->normalizeText($state)),
                                            ])
                                            ->columns(1)
                                            ->columnSpan(1),

                                        Grid::make()
                                            ->columns(1)
                                            ->schema([
                                                Section::make('Ekstrahovani podaci (summary)')
                                                    ->schema([
                                                        TextEntry::make('requested_property')
                                                            ->label('Traženi smeštaj')
                                                            ->state(function (Inquiry $record) {
                                                                $pc = data_get($record->entities, 'property_candidates', []);
                                                                if (!is_array($pc) || empty($pc)) return '-';

                                                                $first = $pc[0] ?? null;
                                                                if (is_string($first)) return $first;

                                                                $name = data_get($first, 'name')
                                                                    ?? data_get($first, 'title')
                                                                    ?? data_get($first, 'query')
                                                                    ?? null;

                                                                return $name ?: '-';
                                                            }),

                                                        TextEntry::make('groups_summary')
                                                            ->label('Porodice / grupe')
                                                            ->state(fn (Inquiry $record) => $this->formatGroupsSummary($record))
                                                            ->columnSpanFull()
                                                            ->extraAttributes($textAttrs)
                                                            ->formatStateUsing(fn ($state) => $this->normalizeText($state))
                                                            ->visible(fn (Inquiry $record) => $this->isMulti($record)),

                                                        Grid::make()
                                                            ->columns([
                                                                'default' => 1,
                                                                'sm'      => 2,
                                                            ])
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
                                                                Grid::make()
                                                                    ->columns([
                                                                        'default' => 1,
                                                                        'sm'      => 2,
                                                                        'lg'      => 3,
                                                                    ])
                                                                    ->schema([
                                                                        TextEntry::make('wants_near_beach')->label('Blizu plaže')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                                        TextEntry::make('wants_parking')->label('Parking')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                                        TextEntry::make('wants_quiet')->label('Mirna')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                                        TextEntry::make('wants_pets')->label('Ljubimci')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                                        TextEntry::make('wants_pool')->label('Bazen')->formatStateUsing(fn ($state) => $state === null ? '-' : ($state ? 'Da' : 'Ne')),
                                                                    ]),
                                                            ]),

                                                        Section::make('Status i meta')
                                                            ->schema([
                                                                Grid::make()
                                                                    ->columns([
                                                                        'default' => 1,
                                                                        'sm'      => 2,
                                                                    ])
                                                                    ->schema([
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
                                                                Grid::make()
                                                                    ->columns([
                                                                        'default' => 1,
                                                                        'sm'      => 2,
                                                                    ])
                                                                    ->schema([
                                                                        TextEntry::make('extraction_mode')->label('Mode')->default('-'),
                                                                        TextEntry::make('language')->label('Jezik')->default('-'),
                                                                    ]),
                                                                TextEntry::make('special_requirements')->label('Napomena')->default('-')->columnSpanFull(),
                                                            ]),
                                                    ]),

                                                Section::make('AI Audit (ai_inquiries)')
                                                    ->collapsible()
                                                    ->collapsed()
                                                    ->schema([
                                                        TextEntry::make('ai_status')->label('ai_inquiries.status')->state(fn (Inquiry $record) => (string) ($record->aiInquiry?->status ?? '-')),
                                                        TextEntry::make('ai_intent')->label('ai_inquiries.intent')->state(fn (Inquiry $record) => (string) ($record->aiInquiry?->intent ?? '-')),

                                                        TextEntry::make('ai_missing_fields')
                                                            ->label('ai_inquiries.missing_fields')
                                                            ->state(fn (Inquiry $record) => $this->prettyJson($record->aiInquiry?->missing_fields))
                                                            ->columnSpanFull()
                                                            ->extraAttributes($textAttrs)
                                                            ->copyable(),

                                                        TextEntry::make('ai_suggestions_payload')
                                                            ->label('ai_inquiries.suggestions_payload')
                                                            ->state(fn (Inquiry $record) => $this->prettyJson($record->aiInquiry?->suggestions_payload))
                                                            ->columnSpanFull()
                                                            ->extraAttributes($textAttrs)
                                                            ->copyable(),

                                                        Grid::make()
                                                            ->columns([
                                                                'default' => 1,
                                                                'sm'      => 2,
                                                            ])
                                                            ->schema([
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
                                ViewEntry::make('suggestions')
                                    ->view('filament.inquiries.suggestions')
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('AI draft odgovor')
                            ->schema([
                                TextEntry::make('ai_draft')
                                    ->label('Predlog odgovora')
                                    ->default('-')
                                    ->columnSpanFull()
                                    ->markdown()
                                    ->extraAttributes([
                                        'class' => 'prose dark:prose-invert max-w-none',
                                    ]),
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

    /**
     * Prefill za unified edit:
     * - doda region_id/location_id iz teksta ako može
     * - doda hotel_id = null (ne pretpostavljamo)
     * - groups min 1
     */
    private function prefillEditSearchForm(Inquiry $i): array
    {
        $toSelect = fn ($v) => $v === null ? '' : ($v ? '1' : '0');

        $regionId = $this->guessRegionIdFromText($i->region);
        $locationId = $this->guessLocationIdFromText($i->location, $regionId);

        $groups = data_get($i, 'party.groups', []);
        if (is_string($groups)) {
            $decoded = json_decode($groups, true);
            $groups = is_array($decoded) ? $decoded : [];
        }
        $groups = is_array($groups) ? $groups : [];

        if (count($groups) === 0) {
            $groups = [[
                'adults'        => (int) ($i->adults ?? 0),
                'children'      => (int) ($i->children ?? 0),
                'children_ages' => is_array($i->children_ages) ? implode(', ', $i->children_ages) : (string) ($i->children_ages ?? ''),
                'requirements'  => '',
            ]];
        } else {
            $groups = collect($groups)->map(function ($g) {
                $g = is_array($g) ? $g : [];
                $ages = data_get($g, 'children_ages', []);
                $req  = data_get($g, 'requirements', []);

                return [
                    'adults'        => (int) data_get($g, 'adults', 0),
                    'children'      => (int) data_get($g, 'children', 0),
                    'children_ages' => is_array($ages) ? implode(', ', $ages) : (string) ($ages ?? ''),
                    'requirements'  => is_array($req) ? implode("\n", $req) : (string) ($req ?? ''),
                ];
            })->values()->all();
        }

        return [
            'region_id'   => $regionId,
            'location_id' => $locationId,
            'hotel_id'    => null,

            'region' => $i->region,
            'location' => $i->location,
            'month_hint' => $i->month_hint,
            'date_from' => $i->date_from,
            'date_to' => $i->date_to,
            'nights' => $i->nights,

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

            'groups' => $groups,
        ];
    }

    /**
     * Save unified edit:
     * - tri-state wants_*
     * - normalize groups + validation (if children > 0 -> ages required)
     * - upiše region/location iz dropdown-a ako izabrano
     * - ako izabran hotel, ubaci u entities.property_candidates[0]
     */
    private function saveEditSearchForm(array $data): void
    {
        /** @var Inquiry $record */
        $record = $this->record;

        foreach (['wants_near_beach','wants_parking','wants_quiet','wants_pets','wants_pool'] as $k) {
            if (! array_key_exists($k, $data)) continue;
            $v = $data[$k];
            if ($v === '' || $v === null) $data[$k] = null;
            elseif ((string) $v === '1') $data[$k] = true;
            elseif ((string) $v === '0') $data[$k] = false;
        }

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

        foreach ($groups as $idx => $g) {
            $c = (int) ($g['children'] ?? 0);
            $ages = $g['children_ages'] ?? [];
            if ($c > 0 && (! is_array($ages) || count($ages) === 0)) {
                Notification::make()
                    ->title('Nedostaje uzrast dece')
                    ->body('Porodica ' . ($idx + 1) . ': ima dece, ali uzrast nije upisan.')
                    ->warning()
                    ->send();
                return;
            }
        }

        // region/location from dropdown (if selected)
        $regionId = !empty($data['region_id']) ? (int) $data['region_id'] : null;
        $locationId = !empty($data['location_id']) ? (int) $data['location_id'] : null;
        $hotelId = !empty($data['hotel_id']) ? (int) $data['hotel_id'] : null;

        if ($regionId) {
            $regionName = GrckaRegion::query()->where('region_id', $regionId)->value('region');
            if ($regionName) $data['region'] = $regionName;
        }

        if ($locationId) {
            $locName = GrckaLocation::query()->where('id', $locationId)->value('location');
            if ($locName) $data['location'] = $locName;
        }

        $record->fill(Arr::only($data, [
            'region','location','month_hint',
            'date_from','date_to','nights',
            'budget_min','budget_max',
            'wants_near_beach','wants_parking','wants_quiet','wants_pets','wants_pool',
            'special_requirements',
            'reply_mode','status',
        ]));

        // hotel selection -> entities.property_candidates[0]
        if ($hotelId) {
            $hotel = GrckaHotel::query()
                ->where('hotel_id', $hotelId)
                ->first(['hotel_id', 'hotel_title', 'custom_name', 'api_name', 'hotel_slug']);

            if ($hotel) {
                $entities = is_array($record->entities) ? $record->entities : [];
                $pc = is_array(data_get($entities, 'property_candidates')) ? data_get($entities, 'property_candidates') : [];

                array_unshift($pc, [
                    'name'       => (string) ($hotel->hotel_title ?: $hotel->custom_name ?: $hotel->api_name ?: 'Hotel'),
                    'hotel_id'   => (int) $hotel->hotel_id,
                    'hotel_slug' => $hotel->hotel_slug ?? null,
                    'confidence' => 1.0,
                    'source'     => 'manual_select',
                ]);

                // dedupe by hotel_id
                $pc = collect($pc)
                    ->unique(fn ($x) => is_array($x) ? (data_get($x, 'hotel_id') ?? json_encode($x)) : (string) $x)
                    ->values()
                    ->all();

                $entities['property_candidates'] = $pc;
                $record->entities = $entities;
            }
        }

        $party = is_array($record->party) ? $record->party : [];
        $party['groups'] = $groups;
        $record->party = $party;

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
