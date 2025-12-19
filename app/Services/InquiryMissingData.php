<?php

namespace App\Services;

use App\Models\Inquiry;
use Illuminate\Support\Str;

class InquiryMissingData
{
    /**
     * - Ako fale ključni podaci (period + broj osoba) -> NE nudimo smeštaje, samo pitanja.
     * - Ako ima dece -> uzrast dece je obavezan.
     * - Budžet je poželjan, ali nije blokator za ponudu (u Fazi 1).
     */
    public static function detect(Inquiry $i): array
    {
        $missing = [];

        $text = mb_strtolower((string) ($i->raw_message ?? ''));

        // 1) Datumi / period (date_from+date_to) ILI month_hint
        $hasMonthHint = filled($i->month_hint);

        if (! $i->date_from && ! $i->date_to && ! $hasMonthHint) {
            $missing[] = 'tačne datume boravka (od–do) ili okvirni period (npr. sredina jula)';
        } elseif (($i->date_from && ! $i->date_to) || (! $i->date_from && $i->date_to)) {
            $missing[] = 'tačne datume boravka (od–do)';
        }

        // 2) Broj odraslih (ključni podatak)
        if (! $i->adults || (int) $i->adults <= 0) {
            $missing[] = 'broj odraslih osoba';
        }

        // 3) Deca + uzrast (1:1)
        $mentionsKids = Str::contains($text, [
            'dete', 'deca', 'djeca', 'klinac', 'klinci', 'beba', 'baby',
            'godina', 'god', 'uzrast',
        ]);

        $children = $i->children;
        $children = ($children === '' || $children === null) ? null : (int) $children;

        $ages = $i->children_ages ?? [];
        $ages = is_array($ages) ? $ages : self::normalizeChildrenAges($ages);

        if ($children === null && $mentionsKids) {
            $missing[] = 'broj dece i uzrast dece';
        } elseif ($children !== null && $children > 0) {
            if (count($ages) === 0) {
                $missing[] = 'uzrast dece';
            }
        }

        // 4) Lokacija / regija (ključni podatak)
        if (! filled($i->region) && ! filled($i->location)) {
            $missing[] = 'željenu lokaciju/regiju (mesto ili oblast u Grčkoj)';
        }

        // 5) Budžet je POŽELJAN ali nije blokator (ne dodajemo u missing)

        return array_values(array_unique(array_filter($missing)));
    }

    /**
     * children_ages može biti: null | string "5,3" | JSON string | array
     * vraća: int[] (valid ages)
     */
    public static function normalizeChildrenAges(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            $out = [];

            foreach ($value as $v) {
                $n = is_numeric($v) ? (int) $v : null;
                if ($n !== null && $n >= 0 && $n <= 25) {
                    $out[] = $n;
                }
            }

            return array_values(array_unique($out));
        }

        if (is_string($value)) {
            $v = trim($value);
            if ($v === '') {
                return [];
            }

            // probaj JSON
            $decoded = json_decode($v, true);
            if (is_array($decoded)) {
                return self::normalizeChildrenAges($decoded);
            }

            // fallback: izvuci brojeve iz stringa
            preg_match_all('/\d{1,2}/', $v, $m);
            $nums = array_map('intval', $m[0] ?? []);
            $nums = array_values(array_unique(array_filter($nums, fn ($n) => $n >= 0 && $n <= 25)));

            return $nums;
        }

        return [];
    }
}
