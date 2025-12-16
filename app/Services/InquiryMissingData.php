<?php

namespace App\Services;

use App\Models\Inquiry;
use Illuminate\Support\Str;

class InquiryMissingData
{
    /**
     * 1:1 zahtev: ako nešto fali -> NE nudimo smeštaje, samo pitanja.
     */
    public static function detect(Inquiry $i): array
    {
        $missing = [];

        $text = mb_strtolower((string) ($i->raw_message ?? ''));

        // 1) Datumi / period
        if (! $i->date_from && ! $i->date_to) {
            $missing[] = 'tačne datume boravka (od–do) ili okvirni period (npr. sredina jula)';
        } elseif (! $i->date_from || ! $i->date_to) {
            $missing[] = 'tačne datume boravka (od–do)';
        }

        // 2) Broj odraslih
        if (! $i->adults) {
            $missing[] = 'broj odraslih osoba';
        }

        // 3) Deca + uzrast (1:1)
        // - Ako je children > 0 => uzrast je obavezan (children_ages mora biti popunjen)
        // - Ako children je null ali tekst pominje decu => tražimo broj dece + uzrast
        $mentionsKids = Str::contains($text, [
            'dete', 'deca', 'djeca', 'klinac', 'klinci', 'beba', 'baby',
            'godina', 'god', 'uzrast'
        ]);

        $children = $i->children;

        // trim children_ages (može biti string ili null)
        $ages = trim((string) ($i->children_ages ?? ''));

        if ($children === null && $mentionsKids) {
            $missing[] = 'broj dece i uzrast dece';
        }

        if (is_int($children) && $children > 0) {
            if ($ages === '') {
                $missing[] = 'uzrast dece';
            }
        }

        // 4) Lokacija / regija
        if (! $i->region && ! $i->location) {
            $missing[] = 'željenu lokaciju/regiju (mesto ili oblast u Grčkoj)';
        }

        // 5) Budžet (poželjno, ali u fazi 1 pitamo ako fali)
        if (! $i->budget_min && ! $i->budget_max) {
            $missing[] = 'budžet (ukupno ili po noći)';
        }

        // unique + clean
        return array_values(array_unique(array_filter($missing)));
    }
}
