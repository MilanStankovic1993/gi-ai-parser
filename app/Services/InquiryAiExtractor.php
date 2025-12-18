<?php

namespace App\Services;

use App\Models\Inquiry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InquiryAiExtractor
{
    public function extract(Inquiry $inquiry): array
    {
        $text = trim((string) ($inquiry->raw_message ?? ''));

        if ($text === '') {
            return $this->fallbackExtract($inquiry);
        }

        $aiEnabled =
            (bool) config('app.ai_enabled', false) ||
            filter_var(env('AI_ENABLED', false), FILTER_VALIDATE_BOOL);

        if (! $aiEnabled) {
            return $this->fallbackExtract($inquiry);
        }

        $apiKey = config('services.openai.key');
        $model  = config('services.openai.model', 'gpt-4.1');

        if (! $apiKey) {
            return $this->fallbackExtract($inquiry);
        }

        $today = now()->toDateString();

        $system = <<<SYS
Ti si asistent za GrckaInfo. Iz jedne poruke gosta treba da izvučeš strukturisane parametre za ponudu smeštaja.

Vrati ISKLJUČIVO validan JSON (bez objašnjenja, bez markdown-a).
Sva polja koja nisu poznata vrati kao null.

DATUMI:
- Ako je naveden interval "od-do" vrati date_from i date_to kao YYYY-MM-DD.
- Ako je naveden samo okvirni period (npr. "sredina jula", "druga polovina juna", "početak avgusta") a nema tačnih dana:
  date_from = null, date_to = null i upiši taj opis u month_hint (string).

OSOBE:
- adults: broj odraslih (int|null)
- children: broj dece (int|null)
- children_ages: niz uzrasta [5,3] ako je pomenuto, inače [] (prazan niz) ili null ako nema informacije.

BUDŽET:
- budget_min / budget_max kao int (EUR)

LOKACIJA:
- region: oblast/regija
- location: mesto

ŽELJE:
- wants_near_beach, wants_parking, wants_quiet, wants_pets, wants_pool kao true/false/null (null ako nije pomenuto)

OSTALO:
- special_requirements: kratak slobodan tekst
- language: "sr" ili "en"
SYS;

        $user = <<<USR
Danas je: {$today}

PORUKA GOSTA:
{$text}

Vrati JSON sa poljima:
region (string|null),
location (string|null),
month_hint (string|null),
date_from (string|null),
date_to (string|null),
nights (int|null),
adults (int|null),
children (int|null),
children_ages (array|null),
budget_min (int|null),
budget_max (int|null),
wants_near_beach (bool|null),
wants_parking (bool|null),
wants_quiet (bool|null),
wants_pets (bool|null),
wants_pool (bool|null),
special_requirements (string|null),
language (string|null)
USR;

        try {
            $payload = [
                'model' => $model,
                'temperature' => 0.1,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => ['type' => 'json_object'],
            ];

            $resp = Http::timeout(40)
                ->retry(2, 400)
                ->withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', $payload);

            if (! $resp->successful()) {
                Log::warning('InquiryAiExtractor: OpenAI non-success', [
                    'status' => $resp->status(),
                    'inquiry_id' => $inquiry->id ?? null,
                ]);
                return $this->fallbackExtract($inquiry);
            }

            $content = data_get($resp->json(), 'choices.0.message.content');
            if (! is_string($content) || trim($content) === '') {
                return $this->fallbackExtract($inquiry);
            }

            $json = $this->decodeJsonSafely($content);
            if (! is_array($json)) {
                return $this->fallbackExtract($inquiry);
            }

            $out = $this->normalize($json, $text);
            $out['_mode'] = 'ai';

            return $out;
        } catch (\Throwable $e) {
            Log::warning('InquiryAiExtractor: exception', [
                'inquiry_id' => $inquiry->id ?? null,
                'message' => $e->getMessage(),
            ]);
            return $this->fallbackExtract($inquiry);
        }
    }

    private function normalize(array $data, string $rawText): array
    {
        $out = [
            'region' => $this->nullIfEmpty(data_get($data, 'region')),
            'location' => $this->nullIfEmpty(data_get($data, 'location')),
            'month_hint' => $this->nullIfEmpty(data_get($data, 'month_hint')),

            'date_from' => $this->normalizeDate(data_get($data, 'date_from')),
            'date_to' => $this->normalizeDate(data_get($data, 'date_to')),
            'nights' => $this->normalizeInt(data_get($data, 'nights')),

            'adults' => $this->normalizeInt(data_get($data, 'adults')),
            'children' => $this->normalizeInt(data_get($data, 'children')),

            'children_ages' => $this->normalizeAges(data_get($data, 'children_ages')),

            'budget_min' => $this->normalizeInt(data_get($data, 'budget_min')),
            'budget_max' => $this->normalizeInt(data_get($data, 'budget_max')),

            'wants_near_beach' => $this->normalizeBool(data_get($data, 'wants_near_beach')),
            'wants_parking'    => $this->normalizeBool(data_get($data, 'wants_parking')),
            'wants_quiet'      => $this->normalizeBool(data_get($data, 'wants_quiet')),
            'wants_pets'       => $this->normalizeBool(data_get($data, 'wants_pets')),
            'wants_pool'       => $this->normalizeBool(data_get($data, 'wants_pool')),

            'special_requirements' => $this->nullIfEmpty(data_get($data, 'special_requirements')),
            'language' => $this->nullIfEmpty(data_get($data, 'language')) ?? 'sr',
        ];

        if (! $out['nights'] && $out['date_from'] && $out['date_to']) {
            try {
                $df = Carbon::parse($out['date_from']);
                $dt = Carbon::parse($out['date_to']);
                $n = $df->diffInDays($dt);
                $out['nights'] = $n > 0 ? $n : null;
            } catch (\Throwable $e) {}
        }

        if (! $out['budget_min'] && ! $out['budget_max']) {
            $b = $this->extractBudgetFromText($rawText);
            $out['budget_min'] = $b['budget_min'];
            $out['budget_max'] = $b['budget_max'];
        }

        $fallbackWants = $this->extractWantsFromText($rawText);
        foreach ($fallbackWants as $k => $v) {
            if ($out[$k] === null) {
                $out[$k] = $v;
            }
        }

        if (! $out['adults']) {
            $out['adults'] = $this->extractAdultsFromText($rawText);
        }
        if ($out['children'] === null) {
            $out['children'] = $this->extractChildrenCountFromText($rawText);
        }

        if ($out['children_ages'] === null) {
            $ages = $this->extractChildrenAgesFromText($rawText);
            $out['children_ages'] = ! empty($ages) ? $ages : null;
        }

        return $out;
    }

    private function decodeJsonSafely(string $content): ?array
    {
        $c = trim($content);

        $firstBrace = strpos($c, '{');
        $lastBrace  = strrpos($c, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $c = substr($c, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        $decoded = json_decode($c, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeInt($v): ?int
    {
        if ($v === null) return null;
        if (is_int($v)) return $v;
        if (is_float($v)) return (int) round($v);
        if (is_bool($v)) return $v ? 1 : 0;

        if (is_string($v)) {
            $n = preg_replace('/[^\d]/', '', $v);
            return $n === '' ? null : (int) $n;
        }

        return null;
    }

    private function normalizeBool($v): ?bool
    {
        if ($v === null) return null;
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v === 1 ? true : ($v === 0 ? false : null);

        if (is_string($v)) {
            $t = mb_strtolower(trim($v));
            if (in_array($t, ['true', 'da', 'yes', '1'], true)) return true;
            if (in_array($t, ['false', 'ne', 'no', '0'], true)) return false;
        }

        return null;
    }

    private function normalizeDate($v): ?string
    {
        if (! is_string($v) || trim($v) === '') return null;
        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function nullIfEmpty($v): ?string
    {
        if (! is_string($v)) return null;
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    private function normalizeAges($v): ?array
    {
        if ($v === null) return null;

        if (is_string($v)) {
            $decoded = json_decode($v, true);
            if (is_array($decoded)) {
                $v = $decoded;
            } else {
                preg_match_all('/\d{1,2}/', $v, $m);
                $v = $m[0] ?? [];
            }
        }

        if (! is_array($v)) return null;

        $ages = [];
        foreach ($v as $item) {
            $n = $this->normalizeInt($item);
            if ($n !== null && $n >= 0 && $n <= 25) {
                $ages[] = $n;
            }
        }

        return array_values(array_unique($ages));
    }

    private function parseMoneyInt(?string $s): ?int
    {
        if (!is_string($s)) return null;

        $s = trim($s);
        if ($s === '') return null;

        $s = preg_replace('/[^\d\.\,\s]/u', '', $s);

        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            if (str_contains($s, ',')) $s = str_replace(',', '.', $s);
            $s = str_replace(' ', '', $s);
            if (preg_match('/\.\d{3}(\D|$)/', $s)) {
                $s = str_replace('.', '', $s);
            }
        }

        $n = (float) $s;
        if ($n <= 0) return null;

        return (int) round($n);
    }

    /**
     * Fallback parser (heuristic) — produkcijski: radi i bez AI.
     */
    private function fallbackExtract(Inquiry $inquiry): array
    {
        $text = trim((string) ($inquiry->raw_message ?? ''));
        $t = mb_strtolower($text);

        [$location, $region] = $this->extractLocationRegionFallback($t);

        $adults = $this->extractAdultsFromText($text);
        $childrenCount = $this->extractChildrenCountFromText($text);
        $childrenAges = $this->extractChildrenAgesFromText($text);

        [$dateFrom, $dateTo] = $this->extractDateRangeFromText($text);

        $monthHint = $this->extractMonthHint($text);

        $nights = null;
        if ($dateFrom && $dateTo) {
            try {
                $n = Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo));
                $nights = $n > 0 ? $n : null;
            } catch (\Throwable $e) {}
        } else {
            $nights = $this->extractNightsFromText($text);
        }

        $budget = $this->extractBudgetFromText($text);
        $wants = $this->extractWantsFromText($text);
        $special = $this->extractSpecialRequirementsText($text);

        return [
            'region' => $region,
            'location' => $location,
            'month_hint' => $monthHint,

            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'nights' => $nights,

            'adults' => $adults,
            'children' => $childrenCount,
            'children_ages' => !empty($childrenAges) ? $childrenAges : null,

            'budget_min' => $budget['budget_min'],
            'budget_max' => $budget['budget_max'],

            'wants_near_beach' => $wants['wants_near_beach'],
            'wants_parking' => $wants['wants_parking'],
            'wants_quiet' => $wants['wants_quiet'],
            'wants_pets' => $wants['wants_pets'],
            'wants_pool' => $wants['wants_pool'],

            'special_requirements' => $special,
            'language' => $this->guessLanguage($text),

            '_mode' => 'fallback',
        ];
    }

    private function extractLocationRegionFallback(string $t): array
    {
        $map = [
            'pefkohori' => ['Pefkohori', 'Halkidiki - Kassandra'],
            'paliouri' => ['Paliouri', 'Halkidiki - Kassandra'],
            'hanioti' => ['Hanioti', 'Halkidiki - Kassandra'],
            'polihrono' => ['Polichrono', 'Halkidiki - Kassandra'],
            'polichrono' => ['Polichrono', 'Halkidiki - Kassandra'],
            'kriopigi' => ['Kriopigi', 'Halkidiki - Kassandra'],

            'stavros' => ['Stavros', 'Thessaloniki region'],
            'asprovalta' => ['Asprovalta', 'Thessaloniki region'],
            'nea vrasna' => ['Nea Vrasna', 'Thessaloniki region'],
            'vrasna' => ['Vrasna', 'Thessaloniki region'],

            'sarti' => ['Sarti', 'Halkidiki - Sithonia'],
            'nikiti' => ['Nikiti', 'Halkidiki - Sithonia'],
            'toroni' => ['Toroni', 'Halkidiki - Sithonia'],
            'vourvourou' => ['Vourvourou', 'Halkidiki - Sithonia'],
        ];

        foreach ($map as $needle => $lr) {
            if (Str::contains($t, $needle)) {
                return [$lr[0], $lr[1]];
            }
        }

        if (Str::contains($t, 'kassandra')) return [null, 'Halkidiki - Kassandra'];
        if (Str::contains($t, 'sithonia')) return [null, 'Halkidiki - Sithonia'];
        if (Str::contains($t, 'halkidiki')) return [null, 'Halkidiki'];

        return [null, null];
    }

    private function extractAdultsFromText(string $text): ?int
    {
        $t = mb_strtolower($text);

        if (preg_match('/(\d+)\s*odrasl\w*/u', $t, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/za\s*(\d+)\s*osob/u', $t, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function extractChildrenCountFromText(string $text): ?int
    {
        $t = mb_strtolower($text);

        if (preg_match('/(\d+)\s*(dece|det[ea]|children)/u', $t, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function extractChildrenAgesFromText(string $text): array
    {
        $t = mb_strtolower($text);
        $ages = [];

        if (preg_match_all('/det[ea]\s*\(?\s*(\d{1,2})\s*(god|g)\w*\)?/u', $t, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $ages[] = (int) $m[1];
            }
        }

        if (preg_match_all('/\b(\d{1,2})\s*(god|g)\b/u', $t, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $n = (int) $m[1];
                if ($n >= 0 && $n <= 25) {
                    $ages[] = $n;
                }
            }
        }

        return array_values(array_unique($ages));
    }

    /**
     * ✅ PRODUKCIJSKI: datumi bez godine -> sledeći realan termin u budućnosti.
     * Podržava: "od 01.09. do 08.09.", "01.09-08.09", "01/09 do 08/09", sa/bez godine.
     */
    private function extractDateRangeFromText(string $text): array
    {
        $t = mb_strtolower($text);

        // 1) dd.mm.yyyy - dd.mm.yyyy (ili do)
        if (preg_match('/\b(\d{1,2})\s*[.\-\/]\s*(\d{1,2})\s*[.\-\/]\s*(\d{4})\s*(?:do|\-)\s*(\d{1,2})\s*[.\-\/]\s*(\d{1,2})\s*[.\-\/]\s*(\d{4})\b/u', $t, $m)) {
            $from = Carbon::createFromDate((int)$m[3], (int)$m[2], (int)$m[1])->startOfDay();
            $to   = Carbon::createFromDate((int)$m[6], (int)$m[5], (int)$m[4])->startOfDay();
            return [$from->toDateString(), $to->toDateString()];
        }

        // 2) dd.mm.yyyy - dd.mm (isti year) (ređe)
        if (preg_match('/\b(\d{1,2})\s*[.\-\/]\s*(\d{1,2})\s*[.\-\/]\s*(\d{4})\s*(?:do|\-)\s*(\d{1,2})\s*[.\-\/]\s*(\d{1,2})\b/u', $t, $m)) {
            $y = (int)$m[3];
            $from = Carbon::createFromDate($y, (int)$m[2], (int)$m[1])->startOfDay();
            $to   = Carbon::createFromDate($y, (int)$m[5], (int)$m[4])->startOfDay();
            return [$from->toDateString(), $to->toDateString()];
        }

        // 3) dd.mm - dd.mm (bez godine) — NAJČEŠĆE kod vas
        // prihvati i tačku posle meseca: 01.09. do 08.09.
        if (preg_match('/\b(?:od\s*)?(\d{1,2})\s*[.\-\/]\s*(\d{1,2})\s*\.?\s*(?:do|\-)\s*(\d{1,2})\s*[.\-\/]\s*(\d{1,2})\s*\.?\b/u', $t, $m)) {
            $d1 = (int)$m[1]; $mo1 = (int)$m[2];
            $d2 = (int)$m[3]; $mo2 = (int)$m[4];

            $from = $this->inferFutureDate($d1, $mo1);
            $to   = $this->inferFutureDate($d2, $mo2);

            // ako end ispadne pre start (npr. 28.12 - 04.01), prebacimo end u sledeću godinu
            if ($to->lte($from)) {
                $to = $to->copy()->addYear();
            }

            return [$from->toDateString(), $to->toDateString()];
        }

        return [null, null];
    }

    /**
     * Vrati sledeći datum (d/m) koji je >= danas.
     * Ako je već prošao u tekućoj godini -> prebaci u sledeću godinu.
     */
    private function inferFutureDate(int $day, int $month): Carbon
    {
        $today = now()->startOfDay();

        // invalid guard
        $month = max(1, min(12, $month));
        $day   = max(1, min(31, $day));

        $candidate = Carbon::create($today->year, $month, 1)->startOfDay();
        $maxDay = $candidate->daysInMonth;
        $day = min($day, $maxDay);

        $candidate = Carbon::create($today->year, $month, $day)->startOfDay();

        if ($candidate->lt($today)) {
            $candidate = $candidate->addYear();
        }

        return $candidate;
    }

    private function extractMonthHint(string $text): ?string
    {
        $t = mb_strtolower($text);

        if (preg_match('/\b(sredina|po[cč]etak|kraj|druga polovina|prva polovina)\s+(januara|februara|marta|aprila|maja|juna|jula|avgusta|septembra|oktobra|novembra|decembra|jan|feb|mar|apr|maj|jun|jul|avg|sep|okt|nov|dec)\b/u', $t, $m)) {
            return trim($m[0]);
        }

        if (preg_match('/\b(u|tokom|krajem|po[cč]etkom)\s+(januaru|februaru|martu|aprilu|maju|junu|julu|avgustu|septembru|oktobru|novembru|decembru)\b/u', $t, $m)) {
            return trim($m[0]);
        }

        if (preg_match('/\b(druga polovina|prva polovina|po[cč]etak|sredina|kraj)\s+\w+\s+ili\s+(druga polovina|prva polovina|po[cč]etak|sredina|kraj)\s+\w+\b/u', $t, $m)) {
            return trim($m[0]);
        }

        return null;
    }

    private function extractNightsFromText(string $text): ?int
    {
        $t = mb_strtolower($text);

        if (preg_match('/\b(\d{1,2})\s*(noc|noci|noćenj|nocenja|night)\w*\b/u', $t, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/\b(\d{1,2})\s*-\s*(\d{1,2})\s*(noc|noci|noćenj|nocenja)\b/u', $t, $m)) {
            return (int) $m[2];
        }

        return null;
    }

    /**
     * ✅ Budžet hvata SAMO ako postoji eur/€ ili reč "budžet"
     * da se "od 01.09 do 08.09" ne protumači kao 1–8 EUR.
     */
    private function extractBudgetFromText(string $text): array
    {
        $t = mb_strtolower($text);

        $hasBudgetContext = Str::contains($t, ['€', 'eur', 'eura', 'euro', 'budžet', 'budzet', 'budget']);

        if (! $hasBudgetContext) {
            return ['budget_min' => null, 'budget_max' => null];
        }

        // od X do Y (mora da ima eur/€ ili "budžet" u blizini)
        if (preg_match('/\b(?:budžet|budzet|budget)?\s*(?:od)\s*([\d\.\,\s]{1,12})\s*(?:eur|eura|euro|€)\s*(?:do|\-)\s*([\d\.\,\s]{1,12})\s*(?:eur|eura|euro|€)\b/u', $t, $m)) {
            return [
                'budget_min' => $this->parseMoneyInt($m[1]),
                'budget_max' => $this->parseMoneyInt($m[2]),
            ];
        }

        // od X do Y (ako piše budžet ... od X do Y, a valuta samo jednom)
        if (preg_match('/\b(?:budžet|budzet|budget)[^0-9]{0,40}od\s*([\d\.\,\s]{1,12})\s*(?:do|\-)\s*([\d\.\,\s]{1,12})\s*(?:eur|eura|euro|€)\b/u', $t, $m)) {
            return [
                'budget_min' => $this->parseMoneyInt($m[1]),
                'budget_max' => $this->parseMoneyInt($m[2]),
            ];
        }

        // do X EUR
        if (preg_match('/\b(?:budžet|budzet|budget)?[^0-9]{0,40}\bdo\s*([\d\.\,\s]{1,12})\s*(?:eur|eura|euro|€)\b/u', $t, $m)) {
            return ['budget_min' => null, 'budget_max' => $this->parseMoneyInt($m[1])];
        }

        // oko X EUR
        if (preg_match('/\b(?:budžet|budzet|budget)?[^0-9]{0,40}\boko\s*([\d\.\,\s]{1,12})\s*(?:eur|eura|euro|€)\b/u', $t, $m)) {
            return ['budget_min' => null, 'budget_max' => $this->parseMoneyInt($m[1])];
        }

        // bilo gde X EUR, ali samo ako postoji "budžet" ili "budget" u poruci
        if (Str::contains($t, ['budžet', 'budzet', 'budget']) && preg_match('/\b([\d\.\,\s]{1,12})\s*(?:eur|eura|euro|€)\b/u', $t, $m)) {
            return ['budget_min' => null, 'budget_max' => $this->parseMoneyInt($m[1])];
        }

        return ['budget_min' => null, 'budget_max' => null];
    }

    private function extractWantsFromText(string $text): array
    {
        $t = mb_strtolower($text);

        $nearBeach =
            Str::contains($t, 'blizu pla') ||
            Str::contains($t, 'blizu plaz') ||
            Str::contains($t, 'do pla') ||
            (Str::contains($t, 'bli') && Str::contains($t, 'plaž'));

        $parking = Str::contains($t, 'parking');
        $quiet   = (Str::contains($t, 'mirno') || Str::contains($t, 'mirna') || Str::contains($t, 'tiho'));
        $pets    = (Str::contains($t, 'ljubim') || Str::contains($t, 'pet') || Str::contains($t, 'pas') || Str::contains($t, 'mack'));
        $pool    = (Str::contains($t, 'bazen') || Str::contains($t, 'pool'));

        return [
            'wants_near_beach' => $nearBeach ? true : null,
            'wants_parking' => $parking ? true : null,
            'wants_quiet' => $quiet ? true : null,
            'wants_pets' => $pets ? true : null,
            'wants_pool' => $pool ? true : null,
        ];
    }

    private function extractSpecialRequirementsText(string $text): ?string
    {
        $t = mb_strtolower($text);

        $parts = [];

        if (Str::contains($t, ['mirno', 'mirna', 'tiho'])) $parts[] = 'Mirna lokacija';
        if (Str::contains($t, ['blizu pla', 'blizu plaz', 'do pla'])) $parts[] = 'Blizu plaže';
        if (Str::contains($t, 'parking')) $parts[] = 'Parking';
        if (Str::contains($t, ['ljubim', 'pas', 'mack'])) $parts[] = 'Kućni ljubimci';
        if (Str::contains($t, ['bazen', 'pool'])) $parts[] = 'Bazen';

        $parts = array_values(array_unique($parts));

        return empty($parts) ? null : implode(', ', $parts);
    }

    private function guessLanguage(string $text): string
    {
        $t = mb_strtolower($text);
        if (Str::contains($t, ['hello', 'hi', 'please', 'regards'])) return 'en';
        return 'sr';
    }
}
