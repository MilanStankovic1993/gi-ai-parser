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
        $lines[] = "Na osnovu informacija iz vašeg upita, u nastavku vam šaljemo nekoliko predloga smeštaja. Ukoliko smo nešto pogrešno razumeli ili želite izmene, slobodno nas ispravite.";
        $lines[] = "";

        if ($period || $pax) {
            $summary = [];
            if ($period) $summary[] = "Period: {$period}";
            if ($pax)    $summary[] = "Osobe: {$pax}";
            $lines[] = implode(" • ", $summary);
            $lines[] = "";
        }

        $top = $candidates->take(5)->values();

        foreach ($top as $idx => $c) {
            $n = $idx + 1;

            $name     = data_get($c, 'name') ?? data_get($c, 'title') ?? data_get($c, 'hotel.hotel_title') ?? 'Smeštaj';
            $location = data_get($c, 'location') ?? data_get($c, 'place') ?? data_get($c, 'hotel.mesto') ?? ($inquiry->location ?? null);

            $type     = data_get($c, 'type') ?? data_get($c, 'unit_type') ?? data_get($c, 'room.room_title') ?? null;
            $capacity = data_get($c, 'capacity') ?? data_get($c, 'max_persons') ?? null;

            $priceTotal = data_get($c, 'price_total')
                ?? data_get($c, 'total_price')
                ?? data_get($c, 'price.total')
                ?? data_get($c, 'price')
                ?? null;

            $priceText = $priceTotal !== null ? $this->money($priceTotal) : null;

            $nights = $inquiry->nights ?: data_get($c, 'price.nights') ?: data_get($c, 'nights');
            $beach  = data_get($c, 'beach_distance') ?? data_get($c, 'distance_to_beach') ?? data_get($c, 'hotel.plaza_udaljenost') ?? null;
            $beachText = $beach ? $this->formatBeach($beach) : null;

            $url = data_get($c, 'url') ?? data_get($c, 'link') ?? data_get($c, 'website_url') ?? data_get($c, 'hotel.url') ?? null;
            if ($url && ! str_starts_with($url, 'http')) {
                $url = 'https://' . ltrim($url, '/');
            }

            $title = $name . ($location ? " – {$location}" : "");
            $lines[] = "{$n}. {$title}";

            $bits = [];
            if ($type) $bits[] = "Tip: {$type}";
            if ($capacity) $bits[] = "Kapacitet: do {$capacity} osobe";
            if ($priceText && $nights) $bits[] = "Cena: {$priceText} za {$nights} noćenja";
            elseif ($priceText) $bits[] = "Cena: {$priceText}";
            if ($beachText) $bits[] = "Plaža: {$beachText}";

            if (! empty($bits)) {
                $lines[] = "• " . implode(" • ", $bits);
            }

            if ($url) {
                $lines[] = "• Link: {$url}";
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

        if ($i->month_hint) {
            return (string) $i->month_hint;
        }

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
        // prihvata: "1250", "1.250", "1 250", "1.250,00", "1250.00"
        $s = trim((string) $value);

        // izbaci valute i sve osim cifara, tačke, zareza i razmaka
        $s = preg_replace('/[^\d\.\,\s]/u', '', $s);

        // ako ima i "," i ".", pretpostavi da je "." hiljade a "," decimale (EU format)
        // npr "1.250,00" -> "1250.00"
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            // ako ima samo ",", tretiraj kao decimalni separator
            if (str_contains($s, ',')) {
                $s = str_replace(',', '.', $s);
            }
            // izbaci razmake (hiljade)
            $s = str_replace(' ', '', $s);
        }

        $amount = (float) $s;

        return number_format((float) round($amount), 0, ',', '.') . " €";
    }

    private function formatBeach($distance): string
    {
        if (is_numeric($distance)) {
            $d = (int) $distance;
            return $d >= 1000 ? round($d / 1000, 1) . " km" : $d . " m";
        }

        return (string) $distance;
    }
}
