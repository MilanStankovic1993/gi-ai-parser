<?php

namespace App\Services\Ai;

use OpenAI\Exceptions\RateLimitException;

class AiReplyGenerator
{
    public function __construct(protected OpenAiClient $ai) {}

    public function generate(string $raw, array $inq, array $suggestions): string
    {
        // dev fallback
        if (! filter_var(env('AI_ENABLED', true), FILTER_VALIDATE_BOOL)) {
            return $this->fallbackText($raw, $inq, $suggestions);
        }

        $prompt = <<<TXT
Korisnički upit:
{$raw}

Strukturisani podaci (JSON):
{$this->pretty($inq)}

Opcije koje SMEMO ponuditi (JSON):
{$this->pretty(array_slice($suggestions, 0, 6))}

Uputstva:
1) Potvrdi šta je korisnik tražio (lokacija/region, broj osoba, budžet ako postoji).
2) Ponudi 3–6 najlogičnijih opcija iz liste (naziv, mesto/oblast, tip sobe, cena od).
3) Ako nema u tačno traženom mestu, reci to i ponudi najbliže alternative.
4) Piši kratko, profesionalno, bez tehničkih detalja.
5) Završi pitanjem da potvrdi datume i odabere opciju.

Vrati samo gotov email tekst.
TXT;

        $systemPrompt = "Ti si AI asistent turističke agencije. Pišeš na srpskom (latinično). Ne izmišljaj hotele – koristi isključivo prosleđenu listu. Ne pominji ID-jeve ni tehničke detalje.";

        try {
            return $this->ai->generateText($systemPrompt, $prompt);
        } catch (RateLimitException $e) {
            \Log::warning('OpenAI rate limit (reply)', ['message' => $e->getMessage()]);
            return $this->fallbackText($raw, $inq, $suggestions);
        } catch (\Throwable $e) {
            \Log::error('OpenAI reply failed', ['message' => $e->getMessage()]);
            return $this->fallbackText($raw, $inq, $suggestions);
        }
    }

    private function pretty($v): string
    {
        return json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function fallbackText(string $raw, array $parsed, array $suggestions): string
    {
        $lines = [];
        $lines[] = "Poštovani,";
        $lines[] = "";
        $lines[] = "Hvala na upitu. Da potvrdimo osnovne informacije:";
        $lines[] = "- Odrasli: " . ($parsed['adults'] ?? '—');
        $lines[] = "- Deca: " . (is_array($parsed['children'] ?? null) ? count($parsed['children']) : '—');
        $lines[] = "- Lokacija/region: " . ($parsed['location'] ?? $parsed['region'] ?? '—');
        $lines[] = "- Budžet po noći: " . ($parsed['budget_per_night'] ?? '—');
        $lines[] = "";
        $lines[] = "Predlozi (preliminarno):";

        foreach (array_slice($suggestions, 0, 3) as $h) {
            $title = $h['hotel_title'] ?? '—';
            $place = $h['hotel_city_name'] ?? ($h['hotel_region'] ?? '—');
            $room  = $h['rooms'][0]['room_title'] ?? '—';
            $price = $h['rooms'][0]['room_basic_price'] ?? ($h['hotel_basic_price'] ?? '—');
            $lines[] = "- {$title} ({$place}) — {$room}, od {$price}€ / noć";
        }

        $lines[] = "";
        $lines[] = "Molimo vas da potvrdite tačne datume i broj noćenja, pa šaljemo finalnu dostupnost i cenu.";
        $lines[] = "";
        $lines[] = "Srdačno,";
        $lines[] = "Grčka Info";

        return implode("\n", $lines);
    }
}
