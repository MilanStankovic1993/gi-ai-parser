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
        $mentionsKids = Str::contains($text, [
            'dete', 'deca', 'djeca', 'klinac', 'klinci', 'beba', 'baby',
            'godina', 'god', 'uzrast'
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

        // 4) Lokacija / regija
        if (! $i->region && ! $i->location) {
            $missing[] = 'željenu lokaciju/regiju (mesto ili oblast u Grčkoj)';
        }

        // 5) Budžet (poželjno, ali u fazi 1 pitamo ako fali)
        if (! $i->budget_min && ! $i->budget_max) {
            $missing[] = 'budžet (ukupno ili po noći)';
        }

        return array_values(array_unique(array_filter($missing)));
    }

    /**
     * children_ages može biti: null | string "5,3" | JSON string | array
     * vraća: int[] (valid ages)
     */
    public static function normalizeChildrenAges(mixed $value): array
    {
        if ($value === null) return [];

        if (is_array($value)) {
            return array_values(array_filter(array_map(function ($v) {
                $n = is_numeric($v) ? (int) $v : null;
                return ($n !== null && $n >= 0 && $n <= 25) ? $n : null;
            }, $value), fn($v) => $v !== null));
        }

        if (is_string($value)) {
            $v = trim($value);
            if ($v === '') return [];

            // probaj JSON
            $decoded = json_decode($v, true);
            if (is_array($decoded)) {
                return self::normalizeChildrenAges($decoded);
            }

            // fallback: izvuci brojeve iz stringa
            preg_match_all('/\d{1,2}/', $v, $m);
            $nums = array_map('intval', $m[0] ?? []);
            $nums = array_values(array_unique(array_filter($nums, fn($n) => $n >= 0 && $n <= 25)));

            return $nums;
        }

        return [];
    }
}
