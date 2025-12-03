<?php

namespace App\Services;

use App\Models\Inquiry;
use Carbon\Carbon;
use Illuminate\Support\Str;

class InquiryAiExtractor
{
    /**
     * "Fejk" ekstrakcija, ali sa strukturom koja je što bliža onome šta bi AI vraćao.
     *
     * Kasnije ovde samo zameniš telo metode da umesto lokalne logike
     * pozoveš pravi AI (OpenAI, Azure...) i vratiš isti shape niza.
     */
    public function extract(Inquiry $inquiry): array
    {
        $text = $inquiry->raw_message ?? '';
        $textLower = mb_strtolower($text);

        // 1) Region / lokacija – najjednostavnije: tražimo poznate nazive u tekstu
        $knownRegions = [
            'stavros',
            'nea vrasna',
            'asprovalta',
            'paralia',
            'olympic beach',
            'leptokaria',
            'hanioti',
            'pefkohori',
            'polichrono',
            'nikiti',
            'sarti',
            'toroni',
            'parga',
            'tasos',
            'thassos',
            'corfu',
            'krf',
            'zakynthos',
            'zakinthos',
        ];

        $detectedRegion = null;
        foreach ($knownRegions as $region) {
            if (Str::contains($textLower, $region)) {
                $detectedRegion = $region;
                break;
            }
        }

        // 2) Odrasli
        $adults = null;
        if (preg_match('/(\d+)\s*(odrasl[aiy]|adult)/u', $textLower, $m)) {
            $adults = (int) $m[1];
        }

        // 3) Deca
        $children = null;
        if (preg_match('/(\d+)\s*(dece|det[ea]|children)/u', $textLower, $m)) {
            $children = (int) $m[1];
        }

        // 4) Datumi: tražimo dd.mm. ili dd.mm.yyyy – uzmemo prva dva kao od/do
        $dateFrom = null;
        $dateTo   = null;

        if (preg_match_all('/(\d{1,2})[.\-\/](\d{1,2})(?:[.\-\/](\d{2,4}))?/u', $textLower, $matches, PREG_SET_ORDER)) {
            if (count($matches) >= 1) {
                $dateFrom = $this->buildDateFromMatch($matches[0]);
            }
            if (count($matches) >= 2) {
                $dateTo = $this->buildDateFromMatch($matches[1]);
            }
        }

        // 5) Broj noćenja – ako imamo datume, računamo; ako ne, tražimo "7 noćenja"
        $nights = $dateFrom && $dateTo
            ? max(1, $dateFrom->diffInDays($dateTo))
            : null;

        // 6) Budžet – tražimo prvu cifru pre "eur", "€", "eura", "euro"
        $budgetMin = null;
        $budgetMax = null;
        if (preg_match('/(\d{2,5})\s*(eur|eura|euro|€)/u', $textLower, $m)) {
            $budgetMax = (int) $m[1];
        }

        // 7) Posebni zahtevi – koristimo niz "flagova" (ovo je shape koji očekujemo od AI)
        $specialRequirements = [];

        if (Str::contains($textLower, 'blizu plaž') || Str::contains($textLower, 'blizu plaz')) {
            $specialRequirements[] = 'close_to_beach';
        }

        if (Str::contains($textLower, 'parking')) {
            $specialRequirements[] = 'parking';
        }

        if (Str::contains($textLower, 'mirno') || Str::contains($textLower, 'mirna')) {
            $specialRequirements[] = 'quiet_location';
        }

        if (Str::contains($textLower, 'buka') || Str::contains($textLower, 'glasno')) {
            $specialRequirements[] = 'noise_sensitive';
        }

        if (Str::contains($textLower, 'ljubimc') || Str::contains($textLower, 'pet')) {
            $specialRequirements[] = 'pets_allowed';
        }

        if (Str::contains($textLower, 'bazen')) {
            $specialRequirements[] = 'pool';
        }

        return [
            // obavezna polja koja unosimo direktno u Inquiry
            'region'      => $detectedRegion ? Str::title($detectedRegion) : null,
            'date_from'   => $dateFrom?->toDateString(),
            'date_to'     => $dateTo?->toDateString(),
            'nights'      => $nights,
            'adults'      => $adults,
            'children'    => $children,
            'budget_min'  => $budgetMin,
            'budget_max'  => $budgetMax,

            // shape koje ćemo tražiti i od AI-ja (kasnije ga možemo snimati u JSON kolonu)
            'special_requirements' => $specialRequirements,
        ];
    }

    /**
     * Pomoćna metoda: od regex match-a napravi Carbon datum.
     */
    protected function buildDateFromMatch(array $match): ?Carbon
    {
        $day   = (int) ($match[1] ?? 1);
        $month = (int) ($match[2] ?? 1);
        $year  = null;

        if (!empty($match[3])) {
            $year = (int) $match[3];
            if ($year < 100) {
                $year += 2000;
            }
        } else {
            $year = now()->year;
        }

        try {
            return Carbon::createFromDate($year, $month, $day);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
