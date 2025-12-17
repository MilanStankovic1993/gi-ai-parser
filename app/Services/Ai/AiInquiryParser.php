<?php

namespace App\Services\Ai;

use Carbon\Carbon;
use Illuminate\Support\Str;

class AiInquiryParser
{
    public function __construct(protected OpenAiClient $ai) {}

    public function parse(string $rawText): array
    {
        // ============================
        // DEV / SAFE MODE (NO OPENAI)
        // ============================
        if (! filter_var(env('AI_ENABLED', true), FILTER_VALIDATE_BOOL)) {
            return $this->fallbackParse($rawText);
        }

        // ============================
        // REAL AI PARSING (OPENAI)
        // ============================

        $schema = [
            'type' => 'object',
            'properties' => [
                'region' => ['type' => ['string', 'null']],
                'location' => ['type' => ['string', 'null']],
                'check_in' => ['type' => ['string', 'null']],
                'nights' => ['type' => ['integer', 'null']],
                'adults' => ['type' => ['integer', 'null']],
                'children' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'age' => ['type' => ['integer', 'null']],
                        ],
                    ],
                ],
                'budget_per_night' => ['type' => ['number', 'null']],
                'wants' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'language' => ['type' => 'string'],
            ],
            'required' => ['language'],
        ];

        $systemPrompt = "Izvuci strogo strukturisane podatke iz upita. Vrati JSON strogo po ovoj šemi:\n"
            . json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $userPrompt = <<<TXT
Upit korisnika:
---
{$rawText}
---

Pravila:
- Ako nema datuma -> check_in = null
- Ako nema dece -> children = []
- Ako nema budžet -> budget_per_night = null
- language: "sr" ako je srpski, inače "en"
TXT;

        return $this->ai->extractJson($systemPrompt, $userPrompt);
    }

    /**
     * Fallback parser kad je AI isključen (ili kad hoćeš da testiraš flow bez OpenAI).
     * Cilj: izvući što više "tvrdo" iz teksta, da sugestije ne budu random.
     */
    protected function fallbackParse(string $rawText): array
    {
        $t = trim($rawText);
        $lower = mb_strtolower($t);

        $language = $this->guessLanguage($lower);

        // 1) Adults
        $adults = $this->extractAdults($lower) ?? 2;

        // 2) Children (count + ages)
        $children = $this->extractChildren($lower); // array of ['age'=>int|null]
        // Ako piše "1 dete" a ne nađemo age, vrati bar jedan element
        if (empty($children) && preg_match('/\b(1|jedno)\s+(dete|deteta)\b/u', $lower)) {
            $children = [['age' => null]];
        }

        // 3) Location + region (minimalno mapiranje da ne ode na Lefkadu ako piše Hanioti)
        [$location, $region] = $this->extractLocationAndRegion($lower);

        // 4) Budget per night
        $budget = $this->extractBudgetPerNight($lower);

        // 5) Nights
        $nights = $this->extractNights($lower);

        // 6) Check-in date (very basic; ako ne nađe, null)
        $checkIn = $this->extractCheckIn($lower);
        $monthHint = $this->extractMonthHint($lower);

        // 7) Wants flags
        $wants = $this->extractWants($lower);

        return [
            'region' => $region,
            'location' => $location,
            'check_in' => $checkIn,
            'nights' => $nights,
            'adults' => $adults,
            'children' => $children,
            'budget_per_night' => $budget,
            'wants' => $wants,
            'month_hint' => $monthHint,
            'language' => $language,
            '_note' => 'AI disabled (AI_ENABLED=false) - heuristic fallback',
        ];
    }

    protected function guessLanguage(string $lower): string
    {
        // super-grubo, ali dovoljno za fallback
        if (preg_match('/\b(please|hello|hi|need|accommodation|hotel|budget|adults|children)\b/u', $lower)) {
            return 'en';
        }
        return 'sr';
    }

    protected function extractAdults(string $lower): ?int
    {
        // "2 odrasle", "2 odrasla", "2 adult"
        if (preg_match('/\b(\d+)\s*(odrasl[aeioy]|adult|adults)\b/u', $lower, $m)) {
            return (int) $m[1];
        }

        // "za 2" (fallback, ali oprezno)
        if (preg_match('/\bza\s+(\d+)\b/u', $lower, $m)) {
            $n = (int) $m[1];
            if ($n > 0 && $n <= 10) return $n;
        }

        return null;
    }

    protected function extractChildren(string $lower): array
    {
        $children = [];

        // "1 dete", "2 dece"
        $count = null;
        if (preg_match('/\b(\d+)\s*(dece|deca|deteta|dete|children)\b/u', $lower, $m)) {
            $count = (int) $m[1];
        }

        // age patterns:
        // "dete (5 godina)", "1 dete 5 god", "deca 3 i 8 godina"
        $ages = [];

        // (5 godina)
        if (preg_match_all('/\((\d{1,2})\s*(god|godina|years?)\)/u', $lower, $mm)) {
            foreach ($mm[1] as $a) $ages[] = (int) $a;
        }

        // "5 godina", "5 god"
        if (preg_match_all('/\b(\d{1,2})\s*(god|godina|years?)\b/u', $lower, $mm)) {
            foreach ($mm[1] as $a) $ages[] = (int) $a;
        }

        // "3 i 8 godina"
        if (preg_match_all('/\b(\d{1,2})\s*(i|&|,)\s*(\d{1,2})\s*(god|godina|years?)\b/u', $lower, $mm)) {
            foreach ($mm as $m) {
                $ages[] = (int) $m[1];
                $ages[] = (int) $m[3];
            }
        }

        $ages = array_values(array_unique(array_filter($ages, fn ($a) => $a >= 0 && $a <= 17)));

        if (!empty($ages)) {
            foreach ($ages as $a) {
                $children[] = ['age' => $a];
            }
            return $children;
        }

        // Ako imamo count, ali ne znamo godine:
        if ($count && $count > 0 && $count <= 6) {
            for ($i = 0; $i < $count; $i++) {
                $children[] = ['age' => null];
            }
        }

        return $children;
    }

    protected function extractBudgetPerNight(string $lower): ?float
    {
        // Prioritet: "po noći", "noć", "noc", "per night"
        if (preg_match('/\b(\d{2,5})\s*(€|eur|eura|euro)\b.*\b(po\s+no[ćc]i|no[ćc]|per\s+night)\b/u', $lower, $m)) {
            return (float) $m[1];
        }

        if (preg_match('/\b(bud[žz]et|do|max)\s*(oko\s*)?(\d{2,5})\s*(€|eur|eura|euro)\b/u', $lower, $m)) {
            return (float) $m[3];
        }

        // fallback: prva cifra uz €
        if (preg_match('/\b(\d{2,5})\s*(€|eur|eura|euro)\b/u', $lower, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    protected function extractNights(string $lower): ?int
    {
        if (preg_match('/\b(\d+)\s*(no[ćc]enja|no[ćc]i|nights?)\b/u', $lower, $m)) {
            $n = (int) $m[1];
            return ($n > 0 && $n <= 60) ? $n : null;
        }
        return null;
    }
    protected function extractMonthHint(string $lower): ?string
    {
        $months = [
            'januar','februar','mart','april','maj','jun','jul','avgust','septembar','oktobar','novembar','decembar'
        ];

        foreach ($months as $m) {
            if (Str::contains($lower, [$m, $m.'u', $m.'a', $m.'u'])) {
                return $m;
            }
        }

        // skraćenice
        $short = [
            'jan' => 'januar', 'feb' => 'februar', 'mar' => 'mart', 'apr' => 'april',
            'jun' => 'jun', 'jul' => 'jul', 'avg' => 'avgust', 'sep' => 'septembar',
            'okt' => 'oktobar', 'nov' => 'novembar', 'dec' => 'decembar',
        ];

        foreach ($short as $k => $full) {
            if (preg_match('/\b'.$k.'\b/u', $lower)) return $full;
        }

        return null;
    }

    protected function extractCheckIn(string $lower): ?string
    {
        // dd.mm or dd/mm
        if (preg_match('/\b(\d{1,2})[.\-\/](\d{1,2})(?:[.\-\/](\d{2,4}))?\b/u', $lower, $m)) {
            $d = (int) $m[1];
            $mo = (int) $m[2];
            $y = !empty($m[3]) ? (int) $m[3] : (int) now()->year;
            if ($y < 100) $y += 2000;

            try {
                return Carbon::createFromDate($y, $mo, $d)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        // "15. jul", "oko 15 jul", "15 jul"
        $months = [
            'januar' => 1, 'februar' => 2, 'mart' => 3, 'april' => 4, 'maj' => 5, 'jun' => 6,
            'jul' => 7, 'avgust' => 8, 'septembar' => 9, 'oktobar' => 10, 'novembar' => 11, 'decembar' => 12,
        ];

        if (preg_match('/\b(\d{1,2})\s*\.?\s*(januar|februar|mart|april|maj|jun|jul|avgust|septembar|oktobar|novembar|decembar)\b/u', $lower, $m)) {
            $d = (int) $m[1];
            $mo = $months[$m[2]] ?? null;
            if (!$mo) return null;

            try {
                return Carbon::createFromDate((int) now()->year, $mo, $d)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    protected function extractLocationAndRegion(string $lower): array
    {
        // varijante i mapiranje ka regionu
        $map = [
            // Halkidiki - Kassandra
            'hanioti' => ['Hanioti', 'Halkidiki - Kassandra'],
            'chanioti' => ['Hanioti', 'Halkidiki - Kassandra'],
            'haniotiju' => ['Hanioti', 'Halkidiki - Kassandra'],
            'pefkohori' => ['Pefkohori', 'Halkidiki - Kassandra'],
            'p efkohori' => ['Pefkohori', 'Halkidiki - Kassandra'],
            'polichrono' => ['Polichrono', 'Halkidiki - Kassandra'],
            'kallithea' => ['Kallithea', 'Halkidiki - Kassandra'],

            // Halkidiki - Sithonia
            'nikiti' => ['Nikiti', 'Halkidiki - Sithonia'],
            'sarti' => ['Sarti', 'Halkidiki - Sithonia'],
            'toroni' => ['Toroni', 'Halkidiki - Sithonia'],
            'vourvourou' => ['Vourvourou', 'Halkidiki - Sithonia'],
            'gerakini' => ['Gerakini', 'Halkidiki - Sithonia'],
            'neos marmaras' => ['Neos Marmaras', 'Halkidiki - Sithonia'],

            // Thassos
            'tasos' => ['Thassos', 'Thassos'],
            'thassos' => ['Thassos', 'Thassos'],

            // Krf
            'krf' => ['Corfu', 'Ionian islands'],
            'corfu' => ['Corfu', 'Ionian islands'],

            // Lefkada
            'lefkada' => ['Lefkada', 'Ionian islands'],

            // Parga
            'parga' => ['Parga', 'Epirus'],
        ];

        foreach ($map as $needle => [$loc, $reg]) {
            if (Str::contains($lower, $needle)) {
                return [$loc, $reg];
            }
        }

        // Ako user kaže "halkidiki" bez mesta
        if (Str::contains($lower, 'halkidiki') || Str::contains($lower, 'halkidik')) {
            return [null, 'Halkidiki'];
        }

        return [null, null];
    }

    protected function extractWants(string $lower): array
    {
        $wants = [];

        // close_to_beach
        if (Str::contains($lower, 'blizu pla') || Str::contains($lower, 'prvi red') || Str::contains($lower, 'uz pla')) {
            $wants[] = 'close_to_beach';
        }

        if (Str::contains($lower, 'parking')) {
            $wants[] = 'parking';
        }

        if (Str::contains($lower, 'mirno') || Str::contains($lower, 'tiho')) {
            $wants[] = 'quiet';
        }

        if (Str::contains($lower, 'bazen') || Str::contains($lower, 'pool')) {
            $wants[] = 'pool';
        }

        if (Str::contains($lower, 'ljubim') || Str::contains($lower, 'pet') || Str::contains($lower, 'kuce') || Str::contains($lower, 'pas')) {
            $wants[] = 'pets_allowed';
        }

        if (Str::contains($lower, 'klima') || Str::contains($lower, 'air condition') || Str::contains($lower, 'ac')) {
            $wants[] = 'ac';
        }

        if (Str::contains($lower, 'wi-fi') || Str::contains($lower, 'wifi')) {
            $wants[] = 'wifi';
        }

        return array_values(array_unique($wants));
    }
}
