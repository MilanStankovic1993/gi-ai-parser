<?php

namespace App\Services;

use App\Models\Inquiry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class InquiryAiExtractor
{
    public function extract(Inquiry $inquiry): array
    {
        $text = trim((string) ($inquiry->raw_message ?? ''));

        // AI toggle (radi i preko config i preko env)
        $aiEnabled =
            (bool) config('app.ai_enabled', false) ||
            (string) env('AI_ENABLED', 'false') === 'true';

        if (! $aiEnabled) {
            return $this->fallbackExtract($inquiry);
        }

        $apiKey = config('services.openai.key');
        $model  = config('services.openai.model', 'gpt-4.1');

        // ako nema ključa ili nije podešen services.openai, fallback
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
- Ako je "budžet oko 800 eur za ceo boravak" -> budget_max=800, budget_min=null
- Ako je "od 700 do 900" -> budget_min=700, budget_max=900

LOKACIJA:
- region: oblast/regija (npr "Halkidiki - Kassandra" ili "Thessaloniki region")
- location: mesto (npr "Pefkohori", "Stavros", "Sarti") ako se može jasno izvući, inače null

ŽELJE:
- wants_near_beach, wants_parking, wants_quiet, wants_pets, wants_pool kao true/false/null (null ako nije pomenuto)

OSTALO:
- special_requirements: kratak slobodan tekst (npr "mirno zbog deteta", "blizu plaže", itd) ako postoji.

language: "sr" ili "en" (proceni po jeziku poruke)
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
            $resp = Http::timeout(40)
                ->withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.1,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);

            if (! $resp->successful()) {
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

        // Nights izračunaj ako ima oba datuma
        if (! $out['nights'] && $out['date_from'] && $out['date_to']) {
            try {
                $df = Carbon::parse($out['date_from']);
                $dt = Carbon::parse($out['date_to']);
                $n = $df->diffInDays($dt);
                $out['nights'] = $n > 0 ? $n : null;
            } catch (\Throwable $e) {}
        }

        // Budžet fallback iz teksta ako AI nije popunio
        if (! $out['budget_min'] && ! $out['budget_max']) {
            $b = $this->extractBudgetFromText($rawText);
            $out['budget_min'] = $b['budget_min'];
            $out['budget_max'] = $b['budget_max'];
        }

        // Ako AI nije dao wants, pokušaj iz teksta (ne prepisuj postojeće true/false)
        $fallbackWants = $this->extractWantsFromText($rawText);
        foreach ($fallbackWants as $k => $v) {
            if ($out[$k] === null) {
                $out[$k] = $v;
            }
        }

        // Ako AI nije dao adults/children, probaj iz teksta (minimalno)
        if (! $out['adults']) {
            $a = $this->extractAdultsFromText($rawText);
            $out['adults'] = $a;
        }
        if ($out['children'] === null) {
            $c = $this->extractChildrenCountFromText($rawText);
            $out['children'] = $c;
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
        if (! is_array($v)) return null;

        $ages = [];
        foreach ($v as $item) {
            $n = $this->normalizeInt($item);
            if ($n !== null && $n >= 0 && $n <= 25) {
                $ages[] = $n;
            }
        }

        return $ages;
    }

    /**
     * Fallback parser (šire nego pre)
     */
    private function fallbackExtract(Inquiry $inquiry): array
    {
        $text = trim((string) ($inquiry->raw_message ?? ''));
        $t = mb_strtolower($text);

        // region + location (jednostavno, ali proširivo)
        [$location, $region] = $this->extractLocationRegionFallback($t);

        // adults/children + ages
        $adults = $this->extractAdultsFromText($text);
        $childrenCount = $this->extractChildrenCountFromText($text);
        $childrenAges = $this->extractChildrenAgesFromText($text);

        // dates range
        [$dateFrom, $dateTo] = $this->extractDateRangeFromText($text);

        // month_hint (okvirni period)
        $monthHint = $this->extractMonthHint($text);

        // nights
        $nights = null;
        if ($dateFrom && $dateTo) {
            try {
                $n = Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo));
                $nights = $n > 0 ? $n : null;
            } catch (\Throwable $e) {}
        } else {
            $nights = $this->extractNightsFromText($text);
        }

        // budget
        $budget = $this->extractBudgetFromText($text);

        // wants
        $wants = $this->extractWantsFromText($text);

        // special requirements (kratko)
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
            'children_ages' => $childrenAges ?: null,

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
        // mesto -> region map (možeš proširiti)
        $map = [
            'pefkohori' => ['Pefkohori', 'Halkidiki - Kassandra'],
            'p efkohori' => ['Pefkohori', 'Halkidiki - Kassandra'],
            'pefkohoriu' => ['Pefkohori', 'Halkidiki - Kassandra'],
            'paliouri' => ['Paliouri', 'Halkidiki - Kassandra'],
            'hanioti' => ['Hanioti', 'Halkidiki - Kassandra'],
            'polihrono' => ['Polichrono', 'Halkidiki - Kassandra'],
            'polichrono' => ['Polichrono', 'Halkidiki - Kassandra'],

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

        // fallback ako piše “Halkidiki”, “Kassandra”, “Sithonia”
        if (Str::contains($t, 'kassandra')) return [null, 'Halkidiki - Kassandra'];
        if (Str::contains($t, 'sithonia')) return [null, 'Halkidiki - Sithonia'];
        if (Str::contains($t, 'halkidiki')) return [null, 'Halkidiki'];

        return [null, null];
    }

    private function extractAdultsFromText(string $text): ?int
    {
        $t = mb_strtolower($text);

        // "2 odrasle osobe", "2 odrasla", "2 odraslih"
        if (preg_match('/(\d+)\s*odrasl\w*/u', $t, $m)) {
            return (int) $m[1];
        }

        // "za 2 osobe"
        if (preg_match('/za\s*(\d+)\s*osob/u', $t, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function extractChildrenCountFromText(string $text): ?int
    {
        $t = mb_strtolower($text);

        // “1 dete”, “2 dece”
        if (preg_match('/(\d+)\s*(dece|det[ea]|children)/u', $t, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function extractChildrenAgesFromText(string $text): array
    {
        $t = mb_strtolower($text);
        $ages = [];

        // “dete (5 godina)” ili “2 dece (5 godina)”
        if (preg_match_all('/det[ea]\s*\(?\s*(\d{1,2})\s*(god|g)\w*\)?/u', $t, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $ages[] = (int) $m[1];
            }
        }

        // “uzrast 5 i 3”
        if (preg_match_all('/\b(\d{1,2})\s*(god|g)\b/u', $t, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $n = (int) $m[1];
                if ($n >= 0 && $n <= 25) {
                    $ages[] = $n;
                }
            }
        }

        // unique
        $ages = array_values(array_unique($ages));

        return $ages;
    }

    private function extractDateRangeFromText(string $text): array
    {
        $t = mb_strtolower($text);

        // 15.08. do 22.08. 2025
        if (preg_match('/\b(\d{1,2})[.\-\/](\d{1,2})[.\-\/]?\s*(?:do|\-)\s*(\d{1,2})[.\-\/](\d{1,2})[.\-\/]?\s*(\d{4})\b/u', $t, $m)) {
            $y = (int) $m[5];
            return [
                Carbon::createFromDate($y, (int) $m[2], (int) $m[1])->toDateString(),
                Carbon::createFromDate($y, (int) $m[4], (int) $m[3])->toDateString(),
            ];
        }

        // 18.06 - 25.06 (godina možda fali)
        if (preg_match('/\b(\d{1,2})[.\-\/](\d{1,2})\s*(?:do|\-)\s*(\d{1,2})[.\-\/](\d{1,2})\b/u', $t, $m)) {
            $y = now()->year;
            return [
                Carbon::createFromDate($y, (int) $m[2], (int) $m[1])->toDateString(),
                Carbon::createFromDate($y, (int) $m[4], (int) $m[3])->toDateString(),
            ];
        }

        // “od 15.08. do 22.08.” (bez godine)
        if (preg_match('/\bod\s*(\d{1,2})[.\-\/](\d{1,2})\s*(?:do|\-)\s*(\d{1,2})[.\-\/](\d{1,2})\b/u', $t, $m)) {
            $y = now()->year;
            return [
                Carbon::createFromDate($y, (int) $m[2], (int) $m[1])->toDateString(),
                Carbon::createFromDate($y, (int) $m[4], (int) $m[3])->toDateString(),
            ];
        }

        return [null, null];
    }

    private function extractMonthHint(string $text): ?string
    {
        $t = mb_strtolower($text);

        // primeri: “sredina jula”, “druga polovina juna”, “početak avgusta”
        if (preg_match('/\b(sredina|po[cč]etak|kraj|druga polovina|prva polovina)\s+(januara|februara|marta|aprila|maja|juna|jula|avgusta|septembra|oktobra|novembra|decembra|jan|feb|mar|apr|maj|jun|jul|avg|sep|okt|nov|dec)\b/u', $t, $m)) {
            return trim($m[0]);
        }

        // “u julu”, “tokom avgusta”
        if (preg_match('/\b(u|tokom|krajem|po[cč]etkom)\s+(januaru|februaru|martu|aprilu|maju|junu|julu|avgustu|septembru|oktobru|novembru|decembru)\b/u', $t, $m)) {
            return trim($m[0]);
        }

        // “druga polovina juna ili početak jula”
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
        // “10-12 noćenja”
        if (preg_match('/\b(\d{1,2})\s*-\s*(\d{1,2})\s*(noc|noci|noćenj|nocenja)\b/u', $t, $m)) {
            return (int) $m[2];
        }
        return null;
    }

    private function extractBudgetFromText(string $text): array
    {
        $t = mb_strtolower($text);

        // “od 700 do 900 eura”
        if (preg_match('/\bod\s*(\d{2,6})\s*(eur|eura|euro|€)?\s*(do|\-)\s*(\d{2,6})\s*(eur|eura|euro|€)\b/u', $t, $m)) {
            return ['budget_min' => (int) $m[1], 'budget_max' => (int) $m[4]];
        }

        // “do 500 eura”
        if (preg_match('/\bdo\s*(\d{2,6})\s*(eur|eura|euro|€)\b/u', $t, $m)) {
            return ['budget_min' => null, 'budget_max' => (int) $m[1]];
        }

        // “oko 800 eur”
        if (preg_match('/\boko\s*(\d{2,6})\s*(eur|eura|euro|€)\b/u', $t, $m)) {
            return ['budget_min' => null, 'budget_max' => (int) $m[1]];
        }

        // fallback: prvi broj uz EUR
        if (preg_match('/\b(\d{2,6})\s*(eur|eura|euro|€)\b/u', $t, $m)) {
            return ['budget_min' => null, 'budget_max' => (int) $m[1]];
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
            (Str::contains($t, 'bli') && Str::contains($t, 'plaž')); // "bliže plaži"
        $parking   = Str::contains($t, 'parking');
        $quiet     = (Str::contains($t, 'mirno') || Str::contains($t, 'mirna') || Str::contains($t, 'tiho'));
        $pets      = (Str::contains($t, 'ljubim') || Str::contains($t, 'pet') || Str::contains($t, 'pas') || Str::contains($t, 'mack'));
        $pool      = (Str::contains($t, 'bazen') || Str::contains($t, 'pool'));

        // vraćaj null ako nije pomenuto (da UI ne laže “Ne”)
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

        if (Str::contains($t, 'mirno') || Str::contains($t, 'mirna') || Str::contains($t, 'tiho')) {
            $parts[] = 'Mirna lokacija';
        }
        if (Str::contains($t, 'blizu pla') || Str::contains($t, 'blizu plaz') || Str::contains($t, 'do pla')) {
            $parts[] = 'Blizu plaže';
        }
        if (Str::contains($t, 'parking')) {
            $parts[] = 'Parking';
        }
        if (Str::contains($t, 'ljubim') || Str::contains($t, 'pas') || Str::contains($t, 'mack')) {
            $parts[] = 'Kućni ljubimci';
        }
        if (Str::contains($t, 'bazen') || Str::contains($t, 'pool')) {
            $parts[] = 'Bazen';
        }

        $parts = array_values(array_unique($parts));
        return empty($parts) ? null : implode(', ', $parts);
    }

    private function guessLanguage(string $text): string
    {
        // dovoljno za start
        $t = mb_strtolower($text);
        if (Str::contains($t, ['hello', 'hi', 'please', 'regards'])) return 'en';
        return 'sr';
    }
}