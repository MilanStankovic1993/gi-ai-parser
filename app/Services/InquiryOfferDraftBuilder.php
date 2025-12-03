<?php

namespace App\Services;

use App\Models\Inquiry;
use Illuminate\Support\Collection;

class InquiryOfferDraftBuilder
{
    public function build(Inquiry $inquiry, Collection $candidates): string
    {
        $lines = [];

        // Uvod – personalizovan ako imamo ime
        $guestName = $inquiry->guest_name ?: 'Poštovani';

        $lines[] = "{$guestName},";
        $lines[] = '';
        $lines[] = 'hvala Vam na upitu i interesovanju za smeštaj. Na osnovu Vaših kriterijuma pripremili smo nekoliko predloga koji najbolje odgovaraju traženim terminima i budžetu.';
        $lines[] = '';

        // Osnovni rezime upita (region, datumi, ljudi, budžet)
        $summaryParts = [];

        if ($inquiry->region) {
            $summaryParts[] = "lokacija: {$inquiry->region}";
        }

        if ($inquiry->date_from && $inquiry->date_to) {
            $summaryParts[] = sprintf(
                'termin: %s – %s',
                $inquiry->date_from->format('d.m.Y'),
                $inquiry->date_to->format('d.m.Y'),
            );
        }

        $people = [];
        if ($inquiry->adults) {
            $people[] = "{$inquiry->adults} odraslih";
        }
        if ($inquiry->children) {
            $people[] = "{$inquiry->children} dece";
        }
        if (! empty($people)) {
            $summaryParts[] = 'broj osoba: ' . implode(' + ', $people);
        }

        if ($inquiry->budget_max) {
            $summaryParts[] = "budžet: do {$inquiry->budget_max} €";
        }

        if (! empty($summaryParts)) {
            $lines[] = 'Sažetak Vašeg upita:';
            $lines[] = '- ' . implode(' | ', $summaryParts);
            $lines[] = '';
        }

        $lines[] = 'Na osnovu toga, predlažemo sledeće opcije:';
        $lines[] = '';

        // Svaki kandidat → jedna “ponuda”
        foreach ($candidates as $index => $item) {
            $acc = $item['accommodation'];

            $number = $index + 1;

            $title = "{$number}. {$acc->name}, {$acc->settlement}, {$acc->region}";

            $lines[] = $title;
            $lines[] = str_repeat('-', mb_strlen($title));

            $lines[] = sprintf(
                'Tip smeštaja: %s (maksimalno %d osoba)',
                $acc->unit_type,
                $acc->max_persons,
            );

            if (! empty($item['total_price']) && ! empty($item['price_per_night'])) {
                $lines[] = sprintf(
                    'Cena za ceo traženi period: %d € (oko %d € po noći)',
                    $item['total_price'],
                    $item['price_per_night'],
                );
            }

            if ($acc->distance_to_beach !== null) {
                $beachLabel = $this->formatBeachType($acc->beach_type);
                $lines[] = sprintf(
                    'Udaljenost do plaže: %d m (%s)',
                    $acc->distance_to_beach,
                    $beachLabel,
                );
            }

            $lines[] = 'Parking: ' . ($acc->has_parking ? 'dostupan' : 'nije dostupan');
            $lines[] = 'Primaju ljubimce: ' . ($acc->accepts_pets ? 'da' : 'ne');

            if ($acc->noise_level) {
                $lines[] = 'Lokacija (buka): ' . $this->formatNoiseLevel($acc->noise_level);
            }

            // Napomena – prednosti / ograničenja
            if ($acc->availability_note) {
                $lines[] = 'Napomena: ' . $acc->availability_note;
            }

            $lines[] = ''; // razmak između ponuda
        }

        $lines[] = 'Sve navedene cene su okvirne i zavise od trenutne dostupnosti u momentu rezervacije.';
        $lines[] = '';
        $lines[] = 'Ukoliko Vam se neka od opcija dopada, pošaljite nam redni broj ponude ili naziv smeštaja, pa ćemo proveriti tačnu dostupnost i poslati Vam finalnu ponudu sa svim detaljima.';
        $lines[] = '';
        $lines[] = 'Srdačan pozdrav,';
        $lines[] = 'Vaš tim za rezervacije';

        return implode("\n", $lines);
    }

    protected function formatBeachType(?string $type): string
    {
        return match ($type) {
            'sand'   => 'peščana plaža',
            'pebble' => 'šljunkovita plaža',
            'mixed'  => 'mešana (pesak/šljunak)',
            'rocky'  => 'stenovita plaža',
            default  => 'plaža',
        };
    }

    protected function formatNoiseLevel(?string $noise): string
    {
        return match ($noise) {
            'quiet'     => 'mirna lokacija',
            'street'    => 'ulica sa umerenim saobraćajem',
            'main_road' => 'blizina magistrale (može biti više buke)',
            default     => 'standardna lokacija',
        };
    }
}
