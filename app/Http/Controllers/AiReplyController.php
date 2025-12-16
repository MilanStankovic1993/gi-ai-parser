<?php

namespace App\Http\Controllers;

use App\Services\Ai\OpenAiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiReplyController extends Controller
{
    public function __construct(
        protected OpenAiClient $client,
    ) {
    }

    public function generate(Request $request): JsonResponse
    {
        $rawText   = (string) $request->input('raw_text', '');
        $parsed    = (array) $request->input('parsed_inquiry', []);
        $hotels    = (array) $request->input('suggested_hotels', []);

        $language  = $parsed['language'] ?? 'sr';

        $systemPrompt = <<<TXT
Ti si AI asistent turističke agencije "Grčka Info".
Pišeš ljubazan, profesionalan odgovor gostu na njegov upit za smeštaj.

PRAVILA STILA:
- Ako je language = "sr", odgovaraj na SRPSKOM (ekavica, latinično pismo).
- Ton neka bude ljubazan, ali ne predugačak i ne "robotski".
- Strukturiši odgovor u nekoliko kraćih pasusa i po potrebi u listu (bullets).
- Uvek se obrati gostu sa "Poštovani/Poštovana" (neutralno: "Poštovani," je ok).

SADRŽAJ:
1. Ukratko potvrdi da si primio upit i ponovi ključne parametre (broj osoba, približan budžet, region/lokaciju ako je poznato).
2. Predstavi 2–5 predloga smeštaja iz dobijene liste (najlogičnije po ceni i kapacitetu) u formi liste:
   - Ime smeštaja
   - Mesto / oblast (npr. "Pefkohori, Halkidiki - Kassandra")
   - Tip i kapacitet jedinice koju predlažeš (npr. "studio za 2–3 osobe")
   - Početna cena po noći (npr. "od 60€ po noći")
3. Napomeni da je dostupnost i konačna cena podložna promeni u zavisnosti od termina, broja noći i eventualnih akcija.
4. Pozovi gosta da potvrdi okvirni termin (datume) i da li mu neki od predloga deluje interesantno.
5. Potpiši se kao tim "Grčka Info".

NEMOJ:
- Nemoj da izmišljaš potpuno nove hotele – koristi isključivo one iz prosleđene liste.
- Nemoj da spominješ ID-jeve hotela niti bilo kakve interne tehničke detalje.
TXT;

        // Da smanjimo veličinu, napravićemo "sažetak" hotela za prompt
        $hotelsForPrompt = collect($hotels)
            ->take(8) // dovoljno je prvih 5–8 za mejl
            ->map(function ($hotel) {
                $firstRoom = $hotel['rooms'][0] ?? null;

                return [
                    'hotel_title'       => $hotel['hotel_title'] ?? '',
                    'hotel_city_name'   => $hotel['hotel_city_name'] ?? '',
                    'hotel_region'      => $hotel['hotel_region'] ?? '',
                    'hotel_basic_price' => $hotel['hotel_basic_price'] ?? null,
                    'suggested_room'    => $firstRoom ? [
                        'room_title'       => $firstRoom['room_title'] ?? '',
                        'room_basic_price' => $firstRoom['room_basic_price'] ?? null,
                        'room_adults'      => $firstRoom['room_adults'] ?? null,
                        'room_children'    => $firstRoom['room_children'] ?? null,
                    ] : null,
                ];
            })
            ->values()
            ->all();

        $userPrompt = json_encode([
            'language'        => $language,
            'guest_raw_text'  => $rawText,
            'parsed_inquiry'  => $parsed,
            'suggested_hotels_for_reply' => $hotelsForPrompt,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $replyText = $this->client->generateText($systemPrompt, $userPrompt);
        } catch (\Throwable $e) {
            \Log::error('AiReplyController: generate failed', [
                'message' => $e->getMessage(),
            ]);
            $replyText = '';
        }

        return response()->json([
            'raw_text'         => $rawText,
            'parsed_inquiry'   => $parsed,
            'suggested_hotels' => $hotels,
            'draft_reply'      => $replyText,
        ]);
    }
}
