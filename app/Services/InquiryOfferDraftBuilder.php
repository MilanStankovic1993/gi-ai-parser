<?php

namespace App\Services;

use App\Models\Inquiry;
use Illuminate\Support\Collection;

class InquiryOfferDraftBuilder
{
    public function build(Inquiry $inquiry, Collection $candidates): string
    {
        $guest = trim((string) ($inquiry->guest_name ?? ''));
        $salutation = $guest !== '' ? "Poštovani {$guest}," : "Poštovani,";

        $period = $this->formatPeriod($inquiry);
        $pax    = $this->formatPax($inquiry);

        $lines = [];
        $lines[] = $salutation;
        $lines[] = "";
        $lines[] = "Hvala vam na javljanju i interesovanju za letovanje u Grčkoj.";
        $lines[] = "";
        $lines[] = "Na osnovu informacija iz vašeg upita, u nastavku vam šaljemo nekoliko predloga smeštaja.";
        $lines[] = "";

        if ($period || $pax) {
            $summary = [];
            if ($period) $summary[] = "Period: {$period}";
            if ($pax)    $summary[] = "Osobe: {$pax}";
            $lines[] = implode(" • ", $summary);
            $lines[] = "";
        }

        // ✅ grupiši po unit_index
        $grouped = $candidates
            ->values()
            ->groupBy(fn ($c) => (int) (data_get($c, 'unit_index') ?? 1))
            ->sortKeys();

        foreach ($grouped as $unitIndex => $items) {
            $lines[] = "=== Apartman / jedinica {$unitIndex} ===";
            $lines[] = "";

            $top = $items->take(5)->values();

            foreach ($top as $idx => $c) {
                $n = $idx + 1;

                $hotelTitle = data_get($c, 'hotel.hotel_title') ?? data_get($c, 'hotel.title') ?? data_get($c, 'name') ?? 'Smeštaj';
                $location = data_get($c, 'hotel.mesto') ?? data_get($c, 'location') ?? ($inquiry->location ?? null);

                $roomTitle = data_get($c, 'room.room_title') ?? data_get($c, 'type') ?? null;

                $total = data_get($c, 'price.total');
                $nights = data_get($c, 'price.nights') ?? $inquiry->nights;

                $priceText = $total !== null ? $this->money($total) : null;

                $url = data_get($c, 'url') ?? data_get($c, 'hotel.link') ?? data_get($c, 'hotel.url') ?? null;
                if ($url && ! str_starts_with($url, 'http')) $url = 'https://' . ltrim($url, '/');

                $title = $hotelTitle . ($location ? " – {$location}" : "");
                $lines[] = "{$n}. {$title}";

                $bits = [];
                if ($roomTitle) $bits[] = "Tip: {$roomTitle}";
                if ($priceText && $nights) $bits[] = "Cena: {$priceText} za {$nights} noćenja";
                elseif ($priceText) $bits[] = "Cena: {$priceText}";

                if (! empty($bits)) $lines[] = "• " . implode(" • ", $bits);
                if ($url) $lines[] = "• Link: {$url}";
                $lines[] = "";
            }
        }

        $questions = is_array($inquiry->questions) ? $inquiry->questions : [];
        if (!empty($questions)) {
            $lines[] = "Napomena:";
            if (in_array('deposit', $questions, true) || in_array('payment', $questions, true)) {
                $lines[] = "• Visina depozita i uslovi plaćanja zavise od izabranog smeštaja — nakon što potvrdite opciju, proveravamo i šaljemo tačne informacije.";
            }
            if (in_array('guarantee', $questions, true)) {
                $lines[] = "• Što se tiče garancije/rezervacije, javićemo vam tačnu proceduru za izabranu opciju.";
            }
            $lines[] = "";
        }

        $lines[] = "Ukoliko vam se neki od predloga dopada, javite nam koji vam je najzanimljiviji kako bismo proverili dostupnost i poslali dalje informacije.";
        $lines[] = "Ako vam je potrebna druga lokacija, drugačiji period ili dodatne opcije, slobodno nam pišite.";
        $lines[] = "";
        $lines[] = "Srdačan pozdrav,";
        $lines[] = "GrckaInfo tim";
        $lines[] = "https://grckainfo.com";

        return implode("\n", $lines);
    }

    private function formatPeriod(Inquiry $i): ?string
    {
        if ($i->date_from && $i->date_to) {
            return $i->date_from->format('d.m.Y') . " – " . $i->date_to->format('d.m.Y');
        }
        if ($i->month_hint) return (string) $i->month_hint;
        return null;
    }

    private function formatPax(Inquiry $i): ?string
    {
        $parts = [];
        if ($i->adults) $parts[] = $i->adults . " odraslih";
        if (is_int($i->children) && $i->children > 0) $parts[] = $i->children . " dece";
        return empty($parts) ? null : implode(", ", $parts);
    }

    private function money($value): string
    {
        $s = trim((string) $value);
        $s = preg_replace('/[^\d\.\,\s]/u', '', $s);

        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            if (str_contains($s, ',')) $s = str_replace(',', '.', $s);
            $s = str_replace(' ', '', $s);
        }

        $amount = (float) $s;
        return number_format((float) round($amount), 0, ',', '.') . " €";
    }
}
