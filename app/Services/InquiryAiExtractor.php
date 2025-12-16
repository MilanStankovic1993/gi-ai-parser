<?php

namespace App\Services;

use App\Models\Inquiry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class InquiryAiExtractor
{
    public function extract(Inquiry $inquiry): array
    {
        $text = trim((string) ($inquiry->raw_message ?? ''));
        $t = mb_strtolower($text);

        // -----------------------------
        // 1) LOCATION / REGION detection
        // -----------------------------
        // U realnosti ovo ćeš kasnije zameniti lookup-om u DB (locations/regions),
        // ali za fallback heuristiku držimo listu najčešćih.
        $knownPlaces = [
            // Halkidiki
            'hanioti' => ['location' => 'Hanioti', 'region' => 'Halkidiki - Kassandra'],
            'halkidiki' => ['location' => null, 'region' => 'Halkidiki'],
            'pefkohori' => ['location' => 'Pefkohori', 'region' => 'Halkidiki - Kassandra'],
            'polichrono' => ['location' => 'Polichrono', 'region' => 'Halkidiki - Kassandra'],
            'nikiti' => ['location' => 'Nikiti', 'region' => 'Halkidiki - Sithonia'],
            'sarti' => ['location' => 'Sarti', 'region' => 'Halkidiki - Sithonia'],
            'toroni' => ['location' => 'Toroni', 'region' => 'Halkidiki - Sithonia'],
            'vourvourou' => ['location' => 'Vourvourou', 'region' => 'Halkidiki - Sithonia'],
            // Paralia / Olimpijska regija
            'paralia' => ['location' => 'Paralia', 'region' => 'Pieria'],
            'olympic beach' => ['location' => 'Olympic Beach', 'region' => 'Pieria'],
            'leptokaria' => ['location' => 'Leptokaria', 'region' => 'Pieria'],
            // Ostalo
            'parga' => ['location' => 'Parga', 'region' => 'Epirus'],
            'tasos' => ['location' => 'Thassos', 'region' => 'Thassos'],
            'thassos' => ['location' => 'Thassos', 'region' => 'Thassos'],
            'krf' => ['location' => 'Corfu', 'region' => 'Ionian islands'],
            'corfu' => ['location' => 'Corfu', 'region' => 'Ionian islands'],
            'zakynthos' => ['location' => 'Zakynthos', 'region' => 'Ionian islands'],
            'zakinthos' => ['location' => 'Zakynthos', 'region' => 'Ionian islands'],
        ];

        $detectedLocation = null;
        $detectedRegion = null;

        foreach ($knownPlaces as $needle => $meta) {
            if (Str::contains($t, $needle)) {
                $detectedLocation = $meta['location'] ?? null;
                $detectedRegion = $meta['region'] ?? null;
                break;
            }
        }

        // -----------------------------
        // 2) Adults
        // -----------------------------
        $adults = null;
        if (preg_match('/(\d+)\s*(odrasl[aiy]|adult)/u', $t, $m)) {
            $adults = (int) $m[1];
        } elseif (preg_match('/za\s*(\d+)\s*osob/u', $t, $m)) {
            $adults = (int) $m[1];
        }

        // -----------------------------
        // 3) Children (count + ages)
        // -----------------------------
        // cilj: children = [ ["age" => 5], ["age" => 3] ] ili [] ako nema
        $children = [];

        // a) “1 dete (5 godina)”
        if (preg_match_all('/(\d+)\s*det[ea]\s*\((\d{1,2})\s*(god|g)\w*\)/u', $t, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $count = (int) $m[1];
                $age   = (int) $m[2];
                for ($i = 0; $i < $count; $i++) {
                    $children[] = ['age' => $age];
                }
            }
        }

        // b) “dete 5 god”
        if (empty($children) && preg_match_all('/det[ea]\s*(\d{1,2})\s*(god|g)\w*/u', $t, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $children[] = ['age' => (int) $m[1]];
            }
        }

        // c) Ako nema godina, ali ima broj dece: “2 dece”
        if (empty($children) && preg_match('/(\d+)\s*(dece|det[ea]|children)/u', $t, $m)) {
            $count = (int) $m[1];
            for ($i = 0; $i < $count; $i++) {
                $children[] = ['age' => null];
            }
        }

        // -----------------------------
        // 4) Dates / month (check_in)
        // -----------------------------
        // Klijent traži “datume ili mesec”.
        // Ovde vraćamo check_in kao "YYYY-MM-DD" kad možemo, ili null.
        // (kasnije možeš proširiti da vraćaš i month_hint).
        $checkIn = null;

        // dd.mm. / dd-mm / dd/mm (+ optional year)
        if (preg_match('/\b(\d{1,2})[.\-\/](\d{1,2})(?:[.\-\/](\d{2,4}))?\b/u', $t, $m)) {
            $checkIn = $this->safeDate((int)$m[1], (int)$m[2], $m[3] ?? null);
        }

        // “15. jul”, “oko 15 jul”
        if (! $checkIn && preg_match('/\b(\d{1,2})\s*[.\-]?\s*(jan|januar|feb|februar|mar|mart|apr|april|maj|jun|juni|jul|juli|avg|avgust|sep|septembar|okt|oktobar|nov|novembar|dec|decembar)\b/u', $t, $m)) {
            $day = (int) $m[1];
            $month = $this->monthToNumber($m[2]);
            if ($month) {
                // ako je mesec ranije od "danas", uzmi sledeću godinu
                $year = now()->year;
                $candidate = Carbon::createFromDate($year, $month, $day);
                if ($candidate->isPast() && $candidate->diffInMonths(now()) > 2) {
                    $year++;
                }
                $checkIn = $this->safeDate($day, $month, (string)$year);
            }
        }

        // “u julu”, “tokom avgusta” -> nemamo dan => check_in ostaje null (možeš dodati month_hint kasnije)
        // -----------------------------
        // 5) Nights
        // -----------------------------
        $nights = null;
        if (preg_match('/\b(\d{1,2})\s*(noc|noci|noćenj|nocenja|night)\w*\b/u', $t, $m)) {
            $nights = (int) $m[1];
        }

        // -----------------------------
        // 6) Budget per night (budget_per_night)
        // -----------------------------
        // “oko 70 evra po noći”, “do 80€”, “budžet 70€”
        $budgetPerNight = null;

        if (preg_match('/\boko\s*(\d{2,5})\s*(eur|eura|euro|€)\b/u', $t, $m)) {
            $budgetPerNight = (float) $m[1];
        } elseif (preg_match('/\bdo\s*(\d{2,5})\s*(eur|eura|euro|€)\b/u', $t, $m)) {
            $budgetPerNight = (float) $m[1];
        } elseif (preg_match('/\b(\d{2,5})\s*(eur|eura|euro|€)\b/u', $t, $m)) {
            $budgetPerNight = (float) $m[1];
        }

        // -----------------------------
        // 7) Wants / special requirements
        // -----------------------------
        $wants = [];

        // blizu plaže
        if (Str::contains($t, 'blizu pla') || Str::contains($t, 'blizu plaz') || Str::contains($t, 'do pla')) {
            $wants[] = 'close_to_beach';
        }

        // parking
        if (Str::contains($t, 'parking')) {
            $wants[] = 'parking';
        }

        // mirno/glasno
        if (Str::contains($t, 'mirno') || Str::contains($t, 'mirna') || Str::contains($t, 'tiho')) {
            $wants[] = 'quiet_location';
        }
        if (Str::contains($t, 'glasno') || Str::contains($t, 'buka') || Str::contains($t, 'magistral')) {
            $wants[] = 'noise_sensitive';
        }

        // ljubimci
        if (Str::contains($t, 'ljubim') || Str::contains($t, 'pet') || Str::contains($t, 'pas') || Str::contains($t, 'mack')) {
            $wants[] = 'pets_allowed';
        }

        // bazen
        if (Str::contains($t, 'bazen') || Str::contains($t, 'pool')) {
            $wants[] = 'pool';
        }

        // tip smeštaja (studio/apartman + spavaće)
        if (Str::contains($t, 'studio')) {
            $wants[] = 'unit_studio';
        }
        if (Str::contains($t, 'apartman')) {
            $wants[] = 'unit_apartment';
        }
        if (preg_match('/\b(\d+)\s*spava[cć]e\b/u', $t, $m)) {
            $wants[] = 'bedrooms_' . (int)$m[1];
        }

        $wants = array_values(array_unique($wants));

        return [
            'region'           => $detectedRegion,
            'location'         => $detectedLocation,
            'check_in'         => $checkIn,          // YYYY-MM-DD ili null
            'nights'           => $nights,
            'adults'           => $adults,
            'children'         => $children,         // [] ili [ ['age'=>..], ... ]
            'budget_per_night' => $budgetPerNight,
            'wants'            => $wants,
            'language'         => 'sr',
            '_note'            => 'fallback extractor (no AI)',
        ];
    }

    private function safeDate(int $day, int $month, ?string $yearRaw): ?string
    {
        $year = $yearRaw ? (int) $yearRaw : now()->year;
        if ($year < 100) {
            $year += 2000;
        }

        try {
            return Carbon::createFromDate($year, $month, $day)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function monthToNumber(string $m): ?int
    {
        $m = mb_strtolower($m);

        $map = [
            'jan' => 1, 'januar' => 1,
            'feb' => 2, 'februar' => 2,
            'mar' => 3, 'mart' => 3,
            'apr' => 4, 'april' => 4,
            'maj' => 5,
            'jun' => 6, 'juni' => 6,
            'jul' => 7, 'juli' => 7,
            'avg' => 8, 'avgust' => 8,
            'sep' => 9, 'septembar' => 9,
            'okt' => 10, 'oktobar' => 10,
            'nov' => 11, 'novembar' => 11,
            'dec' => 12, 'decembar' => 12,
        ];

        return $map[$m] ?? null;
    }
}
