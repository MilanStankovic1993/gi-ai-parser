<?php

namespace App\Services;

use App\Models\Inquiry;
use Illuminate\Support\Str;

class InquiryMissingData
{
    /**
     * Pravila (Faza 1):
     * - Ako fale ključni podaci (period + lokacija + broj osoba) -> NE nudimo smeštaje, samo pitanja.
     * - Ako ima dece -> uzrast dece je obavezan (po grupi, kad postoje grupe).
     * - Budžet je poželjan, ali nije blokator.
     */
    public static function detect(Inquiry $i): array
    {
        $missing = [];

        // intent / out-of-scope
        $intent = (string) ($i->intent ?? 'unknown');
        if (in_array($intent, ['owner_request', 'long_stay_private', 'spam'], true)) {
            $missing[] = "out_of_scope: {$intent}";
            return $missing;
        }

        // tekst (heuristike)
        $text = mb_strtolower((string) ($i->raw_message ?? ''));

        // ---------------------------
        // Travel time
        // ---------------------------
        $travel = is_array($i->travel_time) ? $i->travel_time : [];

        $dateFrom  = $travel['date_from'] ?? ($i->date_from ? $i->date_from->toDateString() : null);
        $dateTo    = $travel['date_to']   ?? ($i->date_to ? $i->date_to->toDateString() : null);
        // $monthHint = $travel['month_hint'] ?? ($i->month_hint ?: null);

        // ✅ Dates gate: exact OR window+nights
        $hasExactDates = filled($i->date_from) && (filled($i->date_to) || (int) $i->nights > 0);

        $winFrom = data_get($i, 'travel_time.date_window.from');
        $winTo   = data_get($i, 'travel_time.date_window.to');
        $hasWindow = filled($winFrom) && filled($winTo);

        $hasNights = (int) (($i->nights ?? null) ?: data_get($i, 'travel_time.nights', 0)) > 0;

        if (! ($hasExactDates || ($hasWindow && $hasNights))) {
            $missing[] = 'tačne datume boravka (od–do) ili okvirni period + broj noćenja (npr. 23.06 ±3 dana na 10 noći)';
        }

        // ---------------------------
        // Location
        // ---------------------------
        if (! filled($i->region) && ! filled($i->location)) {
            $missing[] = 'željenu lokaciju/regiju (mesto ili oblast u Grčkoj)';
        }

        // ---------------------------
        // Party (NEW contract: party.groups)
        // ---------------------------
        $party = is_array($i->party) ? $i->party : [];

        $groups = $party['groups'] ?? null;
        if (is_string($groups)) {
            $decoded = json_decode($groups, true);
            $groups = is_array($decoded) ? $decoded : null;
        }
        $groups = is_array($groups) ? $groups : [];

        // Heuristic: da li poruka pominje decu i uzrast (kada nema strukturiranih podataka)
        $mentionsKids = Str::contains($text, [
            'dete', 'deca', 'djeca', 'klinac', 'klinci', 'beba', 'baby',
        ]);

        // Ako imamo grupe: validacija po grupama
        if (! empty($groups)) {
            $hasAdultsSomewhere = false;
            $hasAnyChildren = false;
            $missingAgesForGroups = false;

            foreach ($groups as $idx => $g) {
                if (! is_array($g)) continue;

                $adults   = self::toIntOrNull($g['adults'] ?? null);
                $children = self::toIntOrNull($g['children'] ?? null);

                if ($adults !== null && $adults > 0) {
                    $hasAdultsSomewhere = true;
                }

                if ($children !== null && $children > 0) {
                    $hasAnyChildren = true;

                    $ages = $g['children_ages'] ?? ($g['ages'] ?? null);
                    $ages = self::normalizeChildrenAges($ages) ?? []; // ✅ FIX: count() safe

                    if (count($ages) === 0) {
                        $missingAgesForGroups = true;
                    } elseif (count($ages) < $children) {
                        $missingAgesForGroups = true;
                    }
                }
            }

            if ($hasAdultsSomewhere && ! $hasAnyChildren && ! $mentionsKids) {
                // samo preskoči kids validaciju, ali nastavi dalje
                // (ovde u groups grani svakako vraćaš rezultat, pa je ok return)
                return array_values(array_unique(array_filter($missing)));
            }

            if (! $hasAdultsSomewhere) {
                $missing[] = 'broj odraslih osoba';
            }

            if (! $hasAnyChildren && $mentionsKids) {
                $missing[] = 'broj dece i uzrast dece';
            } elseif ($missingAgesForGroups) {
                $missing[] = 'uzrast dece';
            }

            return array_values(array_unique(array_filter($missing)));
        }

        // ---------------------------
        // Party fallback (legacy fields)
        // ---------------------------
        $adults = self::toIntOrNull($party['adults'] ?? $i->adults);
        if (! $adults || $adults <= 0) {
            $missing[] = 'broj odraslih osoba';
        }

        $children = self::toIntOrNull($party['children'] ?? $i->children);

        $ages = $party['ages'] ?? $party['children_ages'] ?? $i->children_ages ?? [];
        $ages = self::normalizeChildrenAges($ages) ?? []; // ✅ FIX: count() safe

        if ($children === null && $mentionsKids) {
            $missing[] = 'broj dece i uzrast dece';
        } elseif ($children !== null && $children > 0) {
            if (count($ages) === 0) {
                $missing[] = 'uzrast dece';
            } elseif (count($ages) < $children) {
                $missing[] = 'uzrast dece';
            }
        }

        return array_values(array_unique(array_filter($missing)));
    }

    private static function toIntOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_int($v)) return $v;
        if (is_float($v)) return (int) round($v);
        if (is_bool($v)) return $v ? 1 : 0;

        if (is_string($v)) {
            $n = preg_replace('/[^\d]/', '', $v);
            return $n === '' ? null : (int) $n;
        }

        return null;
    }

    /**
     * children_ages može biti: null | string "5,3" | JSON string | array
     * vraća: int[] (valid ages)
     */
    public static function normalizeChildrenAges($value): ?array
    {
        if ($value === null) {
            return null;
        }

        $normalizeOne = function ($n): ?int {
            if ($n === null) return null;

            $n = (int) $n;

            if ($n === 0) {
                $n = 1;
            }

            // valid range
            if ($n >= 1 && $n <= 17) {
                return $n;
            }

            return null;
        };

        // već array
        if (is_array($value)) {
            $out = [];

            foreach ($value as $v) {
                if ($v === null) continue;

                $s = trim((string) $v);
                if ($s === '') continue;

                if (preg_match('/\d+/', $s, $m)) {
                    $n = $normalizeOne($m[0]);
                    if ($n !== null) {
                        $out[] = $n; // ✅ NE unique
                    }
                }
            }

            return count($out) ? array_values($out) : null;
        }

        // string
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }

        if (
            (str_starts_with($s, '[') && str_ends_with($s, ']')) ||
            (str_starts_with($s, '{') && str_ends_with($s, '}'))
        ) {
            $decoded = json_decode($s, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return self::normalizeChildrenAges($decoded);
            }
        }

        $parts = preg_split('/[,\s;\/]+/', $s) ?: [];
        $out = [];

        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p === '') continue;

            if (preg_match('/\d+/', $p, $m)) {
                $n = $normalizeOne($m[0]);
                if ($n !== null) {
                    $out[] = $n;
                }
            }
        }

        return count($out) ? array_values($out) : null;
    }
}
