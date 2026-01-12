<?php

namespace App\Services;

use App\Models\Inquiry;
use Carbon\Carbon;
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

        $grouped = $candidates
            ->values()
            ->groupBy(fn ($c) => (int) (data_get($c, 'unit_index') ?? 1))
            ->sortKeys();

        $groups  = $this->getPartyGroups($inquiry);
        $isMulti = count($groups) >= 2;

        foreach ($grouped as $unitIndex => $items) {
            $lines[] = "=== Apartman / jedinica {$unitIndex} ===";

            if ($isMulti) {
                $g = $groups[$unitIndex - 1] ?? null;

                $unitAdults = (int) data_get($g, 'adults', 0);
                $unitKids   = (int) data_get($g, 'children', 0);

                $ages = data_get($g, 'children_ages', []);
                if (is_string($ages)) {
                    $decoded = json_decode($ages, true);
                    $ages = is_array($decoded) ? $decoded : [];
                }
                $ages = is_array($ages) ? array_values($ages) : [];
                $agesTxt = ($unitKids > 0 && count($ages)) ? implode(', ', $ages) : null;

                if ($unitAdults > 0 || $unitKids > 0) {
                    $s = "Sastav: {$unitAdults} odraslih, {$unitKids} dece";
                    if ($agesTxt) $s .= " (uzrast: {$agesTxt})";
                    $lines[] = $s;
                }
            }

            $lines[] = "";

            $top = $items->take(5)->values();

            foreach ($top as $idx => $c) {
                $n = $idx + 1;

                $hotel = data_get($c, 'hotel', []);

                $hotelTitle = data_get($hotel, 'hotel_title')
                    ?? data_get($hotel, 'title')
                    ?? data_get($c, 'name')
                    ?? 'Smeštaj';

                $location = data_get($hotel, 'mesto')
                    ?? data_get($c, 'location')
                    ?? ($inquiry->location ?? null);

                $roomTitle = data_get($c, 'room.room_title') ?? data_get($c, 'type') ?? null;

                $total  = data_get($c, 'price.total');
                $nights = data_get($c, 'price.nights') ?? $inquiry->nights;

                $priceText = $total !== null ? $this->money($total) : null;

                $titleText = $hotelTitle . ($location ? " – {$location}" : "");

                // ✅ pokušaj da nađeš kanonski /sr/smestaj/slug/id/
                // ako ne može, vraća raw link (ako postoji), čisto da bude klikabilno
                $url = $this->resolvePublicUrl($hotel, $c);

                if ($url) {
                    $lines[] = "{$n}. [{$titleText}]({$url})";
                } else {
                    $lines[] = "{$n}. {$titleText}";
                }

                $bits = [];
                if ($roomTitle) $bits[] = "Tip: {$roomTitle}";
                if ($priceText && $nights) $bits[] = "Cena: {$priceText} za {$nights} noćenja";
                elseif ($priceText) $bits[] = "Cena: {$priceText}";

                if (! empty($bits)) {
                    $lines[] = "• " . implode(" • ", $bits);
                }

                $lines[] = "";
            }
        }

        $questions = is_array($inquiry->questions) ? $inquiry->questions : [];
        if (! empty($questions)) {
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

    private function resolvePublicUrl($hotel, $candidate): ?string
    {
        // prvo pokupi "raw" link iz bilo kog polja koje već imaš
        $raw = data_get($hotel, 'public_url')
            ?? data_get($hotel, 'link')
            ?? data_get($hotel, 'url')
            ?? data_get($candidate, 'url')
            ?? data_get($candidate, 'link')
            ?? null;

        $raw = $raw ? trim((string) $raw) : null;

        // 1) Ako već dobijamo tačan kanonski link – koristi ga
        if ($raw && preg_match('~^https?://~i', $raw) && preg_match('~/sr/smestaj/[^/]+/\d+/?$~i', $raw)) {
            return $raw;
        }

        // 2) Izvuci slug iz raw linka (ako je tipa /smestaj/sias-cozy-house ili /sr/smestaj/sias-cozy-house)
        $slugFromRaw = null;
        if ($raw && preg_match('~/(?:sr/)?smestaj/([^/]+)/?~i', $raw, $m)) {
            $slugFromRaw = $m[1] ?? null;
            $slugFromRaw = is_string($slugFromRaw) ? trim($slugFromRaw) : null;
        }

        // 3) Skupi ID iz što više realnih varijanti
        $id = data_get($hotel, 'id')
            ?? data_get($hotel, 'hotel_id')
            ?? data_get($hotel, 'smestaj_id')
            ?? data_get($hotel, 'object_id')
            ?? data_get($hotel, 'listing_id')
            ?? data_get($hotel, 'pt_hotel_id')
            ?? data_get($candidate, 'hotel.id')
            ?? data_get($candidate, 'hotel.hotel_id')
            ?? data_get($candidate, 'hotel.smestaj_id')
            ?? data_get($candidate, 'hotel_id')
            ?? data_get($candidate, 'smestaj_id')
            ?? data_get($candidate, 'accommodation_id')
            ?? data_get($candidate, 'object_id')
            ?? data_get($candidate, 'listing_id')
            ?? data_get($candidate, 'id')
            ?? null;

        $id = is_numeric($id) ? (int) $id : null;

        // 4) Skupi slug iz više polja, ili fallback na raw slug
        $slug = data_get($hotel, 'slug')
            ?? data_get($candidate, 'hotel.slug')
            ?? data_get($candidate, 'hotel_slug')
            ?? data_get($candidate, 'slug')
            ?? $slugFromRaw
            ?? null;

        $slug = is_string($slug) ? trim($slug) : null;

        // ✅ ako imamo id+slug -> pravi tačan link koji radi
        if ($id && $slug) {
            return "https://grckainfo.com/sr/smestaj/{$slug}/{$id}/";
        }

        // 5) Ako ne možemo kanonski, vrati raw samo da bude klikabilno (može biti 404 ako sajt traži ID)
        // Ako NE želiš ni raw (da izbegneš 404), samo vrati null umesto ovoga.
        if ($raw) {
            // popravi www varijantu
            $raw = preg_replace('~^https?://www\.grckainfo\.com~i', 'https://grckainfo.com', $raw) ?: $raw;
            return $raw;
        }

        return null;
    }

    private function getPartyGroups(Inquiry $i): array
    {
        $groups = data_get($i, 'party.groups', []);
        if (is_string($groups)) {
            $decoded = json_decode($groups, true);
            $groups = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($groups)) return [];

        return collect($groups)->map(function ($g) {
            $g = is_array($g) ? $g : [];
            $g['adults'] = (int) ($g['adults'] ?? 0);
            $g['children'] = (int) ($g['children'] ?? 0);

            $ages = $g['children_ages'] ?? [];
            if (is_string($ages)) {
                $decoded = json_decode($ages, true);
                $ages = is_array($decoded) ? $decoded : [];
            }
            $g['children_ages'] = is_array($ages) ? array_values($ages) : [];

            return $g;
        })->values()->all();
    }

    private function formatPeriod(Inquiry $i): ?string
    {
        if ($i->date_from && $i->date_to) {
            return $i->date_from->format('d.m.Y') . " – " . $i->date_to->format('d.m.Y');
        }

        $wf = data_get($i, 'travel_time.date_window.from');
        $wt = data_get($i, 'travel_time.date_window.to');
        $n  = (int) (data_get($i, 'travel_time.nights') ?: ($i->nights ?? 0));

        if ($wf && $wt) {
            $from = Carbon::parse($wf)->format('d.m.Y');
            $to   = Carbon::parse($wt)->format('d.m.Y');
            return $n > 0
                ? "{$from} – {$to} (fleksibilno, {$n} noćenja)"
                : "{$from} – {$to} (fleksibilno)";
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
