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
     *
     * DOPUNA:
     * - Ako je intent = specific_property i imamo traženi smeštaj (requested hotel),
     *   lokacija/regija nije obavezna (prvo tražimo po objektu).
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

        $text = mb_strtolower((string) ($i->raw_message ?? ''));

        // ---------------------------
        // Travel time (RELAXED)
        // ---------------------------
        $travel = is_array($i->travel_time) ? $i->travel_time : [];
        $monthHint = $travel['month_hint'] ?? ($i->month_hint ?: null);

        $hasDateFrom = filled($i->date_from) || filled($travel['date_from'] ?? null);

        $winFrom = data_get($i, 'travel_time.date_window.from');
        $winTo   = data_get($i, 'travel_time.date_window.to');
        $hasWindow = filled($winFrom) && filled($winTo);

        $hasNights = (int) (($i->nights ?? null) ?: data_get($i, 'travel_time.nights', 0)) > 0;

        $hasAnyTimeHint = $hasDateFrom || filled($monthHint) || ($hasWindow && $hasNights);

        if (! $hasAnyTimeHint) {
            $missing[] = 'period boravka (npr. od 01.08 ili avgust)';
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

        $mentionsKids = Str::contains($text, [
            'dete', 'deca', 'djeca', 'klinac', 'klinci', 'beba', 'baby',
        ]);

        // Ako imamo grupe: validacija po grupama
        if (! empty($groups)) {
            $hasAdultsSomewhere = false;
            $hasAnyChildren = false;
            $missingAgesForGroups = false;

            foreach ($groups as $g) {
                if (! is_array($g)) continue;

                $adults   = self::toIntOrNull($g['adults'] ?? null);
                $children = self::toIntOrNull($g['children'] ?? null);

                if ($adults !== null && $adults > 0) $hasAdultsSomewhere = true;

                if ($children !== null && $children > 0) {
                    $hasAnyChildren = true;

                    $ages = $g['children_ages'] ?? ($g['ages'] ?? null);
                    $ages = self::normalizeChildrenAges($ages) ?? [];

                    if (count($ages) === 0 || count($ages) < $children) {
                        $missingAgesForGroups = true;
                    }
                }
            }

            if (! $hasAdultsSomewhere) {
                $missing[] = 'broj odraslih osoba';
            }

            if (! $hasAnyChildren && $mentionsKids) {
                $missing[] = 'broj dece i uzrast dece';
            } elseif ($missingAgesForGroups) {
                $missing[] = 'uzrast dece';
            }

            // Location gate ide POSLE ovoga (da imamo intent + requested property info)
            // ali u groups grani ne prekidamo ranije – nastavljamo dalje.
        } else {
            // ---------------------------
            // Party fallback (legacy fields)
            // ---------------------------
            $adults = self::toIntOrNull($party['adults'] ?? $i->adults);
            if (! $adults || $adults <= 0) {
                $missing[] = 'broj odraslih osoba';
            }

            $children = self::toIntOrNull($party['children'] ?? $i->children);

            $ages = $party['ages'] ?? $party['children_ages'] ?? $i->children_ages ?? [];
            $ages = self::normalizeChildrenAges($ages) ?? [];

            if ($children === null && $mentionsKids) {
                $missing[] = 'broj dece i uzrast dece';
            } elseif ($children !== null && $children > 0) {
                if (count($ages) === 0 || count($ages) < $children) {
                    $missing[] = 'uzrast dece';
                }
            }
        }

        // ---------------------------
        // Requested property short-circuit (IMPORTANT)
        // ---------------------------
        // Ako korisnik jasno traži objekat, lokacija nije obavezna.
        $hasRequestedProperty = self::hasRequestedProperty($i);

        // ---------------------------
        // Location (ONLY if needed)
        // ---------------------------
        if (! $hasRequestedProperty) {
            if (! filled($i->region) && ! filled($i->location)) {
                $missing[] = 'željenu lokaciju/regiju (mesto ili oblast u Grčkoj)';
            }
        }

        return array_values(array_unique(array_filter($missing)));
    }

    /**
     * True ako imamo traženi smeštaj iz extractor-a ili iz poruke.
     */
    private static function hasRequestedProperty(Inquiry $i): bool
    {
        if ((string) ($i->intent ?? '') !== 'specific_property') {
            return false;
        }

        // 1) units.property_candidates
        $units = $i->units;

        if (is_string($units)) {
            $decoded = json_decode($units, true);
            $units = is_array($decoded) ? $decoded : [];
        }

        if (is_array($units)) {
            foreach ($units as $u) {
                if (! is_array($u)) continue;

                $pc = $u['property_candidates'] ?? [];
                if (is_string($pc)) {
                    $decoded = json_decode($pc, true);
                    $pc = is_array($decoded) ? $decoded : [];
                }

                if (is_array($pc) && count($pc) > 0) {
                    foreach ($pc as $row) {
                        $q = is_array($row) ? trim((string) ($row['query'] ?? '')) : trim((string) $row);
                        if ($q !== '') return true;
                    }
                }
            }
        }

        // 2) extraction_debug.requested_hotels
        if (is_array($i->extraction_debug ?? null)) {
            $req = $i->extraction_debug['requested_hotels'] ?? null;
            if (is_array($req)) {
                foreach ($req as $n) {
                    if (trim((string) $n) !== '') return true;
                }
            }
        }

        // 3) raw_message heuristic: "informacija o X", "da li ima slobodno u X", "interesuje me X"
        $raw = mb_strtolower((string) ($i->raw_message ?? ''));
        if ($raw !== '') {
            if (preg_match('/(informacija o|info o|slobodno u|za)\s+([^\n\r\.\,]{3,})/iu', $raw, $m)) {
                $candidate = trim((string) ($m[2] ?? ''));
                if (mb_strlen($candidate) >= 3) return true;
            }
        }

        return false;
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

            if ($n >= 1 && $n <= 17) {
                return $n;
            }

            return null;
        };

        if (is_array($value)) {
            $out = [];

            foreach ($value as $v) {
                if ($v === null) continue;

                $s = trim((string) $v);
                if ($s === '') continue;

                if (preg_match('/\d+/', $s, $m)) {
                    $n = $normalizeOne($m[0]);
                    if ($n !== null) {
                        $out[] = $n;
                    }
                }
            }

            return count($out) ? array_values($out) : null;
        }

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
