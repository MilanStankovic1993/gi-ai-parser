<?php

namespace App\Services;

use App\Models\Inquiry;
use Illuminate\Support\Collection;

class InquiryOfferDraftBuilder
{
    public function build(Inquiry $inquiry, Collection $candidates): string
    {
        $guest = $inquiry->guest_name ?: 'Poštovani';

        $period = $this->formatPeriod($inquiry);
        $pax = $this->formatPax($inquiry);

        $lines = [];

        $lines[] = "Poštovani,";
        $lines[] = "";
        $lines[] = "Hvala vam na javljanju i interesovanju za letovanje u Grčkoj.";
        $lines[] = "";
        $lines[] = "Na osnovu informacija iz vašeg upita, u nastavku vam šaljemo nekoliko predloga smeštaja koji bi mogli da odgovaraju vašim željama. Ukoliko smo nešto pogrešno razumeli ili želite izmene, slobodno nas ispravite.";
        $lines[] = "";

        // (opciono) kratki rezime - ako hoćeš da ga imaš uvek
        if ($period || $pax) {
            $summary = [];
            if ($period) $summary[] = "Period: {$period}";
            if ($pax) $summary[] = "Osobe: {$pax}";
            $lines[] = implode(" • ", $summary);
            $lines[] = "";
        }

        $top = $candidates->take(5)->values();

        foreach ($top as $idx => $c) {
            $n = $idx + 1;

            $name = data_get($c, 'name') ?? data_get($c, 'title') ?? 'Smeštaj';
            $location = data_get($c, 'location') ?? data_get($c, 'place') ?? $inquiry->location ?? null;

            $type = data_get($c, 'type') ?? data_get($c, 'unit_type') ?? null;
            $capacity = data_get($c, 'capacity') ?? data_get($c, 'max_persons') ?? null;

            // cena: pokušaj total za period; fallback na bilo koji price
            $priceTotal = data_get($c, 'price_total') ?? data_get($c, 'total_price') ?? data_get($c, 'price') ?? null;
            $priceText = $priceTotal ? $this->money($priceTotal) : null;

            $nights = $inquiry->nights ?: data_get($c, 'nights');
            $beach = data_get($c, 'beach_distance') ?? data_get($c, 'distance_to_beach') ?? null;
            $beachText = $beach ? $this->formatBeach($beach) : null;

            $url = data_get($c, 'url') ?? data_get($c, 'link') ?? data_get($c, 'website_url') ?? null;
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

        // Filament markdown TextEntry voli plain text sa \n
        return implode("\n", $lines);
    }

    private function formatPeriod(Inquiry $i): ?string
    {
        if ($i->date_from && $i->date_to) {
            return $i->date_from->format('d.m.Y') . " – " . $i->date_to->format('d.m.Y');
        }

        if ($i->month_hint) {
            return $i->month_hint;
        }

        return null;
    }

    private function formatPax(Inquiry $i): ?string
    {
        $parts = [];
        if ($i->adults) $parts[] = $i->adults . " odraslih";
        if (is_int($i->children) && $i->children > 0) $parts[] = $i->children . " dece";

        if (empty($parts)) return null;

        return implode(", ", $parts);
    }

    private function money($value): string
    {
        $n = (int) preg_replace('/\D+/', '', (string) $value);
        return number_format($n, 0, ',', '.') . " €";
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
