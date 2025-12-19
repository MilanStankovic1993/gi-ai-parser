<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\Grcka\Hotel;
use App\Models\Grcka\Room;
use App\Models\Grcka\RoomPrice;
use App\Models\Grcka\RoomAvailability;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InquiryAccommodationMatcher
{
    /**
     * Tekstualne kolone u pt_locations koje su korisne za fuzzy lookup
     */
    private static array $locationTextColumns = ['location', 'title', 'h1', 'region', 'link', 'desc'];

    /**
     * Cache cena po sobi (da ne tucamo DB po sobi u petlji)
     * [roomId => Collection<RoomPrice>]
     */
    private array $pricesCache = [];

    public function match(Inquiry $inquiry, int $limit = 5): Collection
    {
        $out = $this->matchWithAlternatives($inquiry, $limit, $limit);

        return collect($out['primary'] ?? []);
    }

    public function matchWithAlternatives(Inquiry $inquiry, int $primaryLimit = 5, int $altLimit = 5): array
    {
        $log = [
            'input' => [
                'date_from'  => optional($inquiry->date_from)->toDateString(),
                'date_to'    => optional($inquiry->date_to)->toDateString(),
                'nights'     => $inquiry->nights,
                'adults'     => $inquiry->adults,
                'children'   => $inquiry->children,
                'region'     => $inquiry->region,
                'location'   => $inquiry->location,
                'budget_max' => $inquiry->budget_max,
            ],
            'reason' => null,
            'steps'  => [],
        ];

        if (! $inquiry->date_from || ! $inquiry->date_to || ! $inquiry->adults) {
            $log['reason'] = 'missing_required_fields_for_offer';

            return ['primary' => collect(), 'alternatives' => collect(), 'log' => $log];
        }

        $from = Carbon::parse($inquiry->date_from)->startOfDay();
        $to   = Carbon::parse($inquiry->date_to)->startOfDay();

        $nights = (int) ($inquiry->nights ?: $from->diffInDays($to));
        if ($nights <= 0) {
            $log['reason'] = 'invalid_nights';

            return ['primary' => collect(), 'alternatives' => collect(), 'log' => $log];
        }

        $adults   = (int) $inquiry->adults;
        $children = (int) ($inquiry->children ?: 0);

        // PRIMARY (strogo mesto)
        $primary = $this->runMatch(
            inquiry: $inquiry,
            from: $from,
            to: $to,
            nights: $nights,
            adults: $adults,
            children: $children,
            limit: $primaryLimit,
            strictLocation: true,
            regionOverride: $this->clean($inquiry->region),
            log: $log
        );

        $log['steps'][] = ['step' => 'primary_count', 'count' => $primary->count()];

        if ($primary->isNotEmpty()) {
            $log['reason'] = 'found_primary';

            return ['primary' => $primary, 'alternatives' => collect(), 'log' => $log];
        }

        // ALTS (opusti mesto)
        $alternatives = $this->runMatch(
            inquiry: $inquiry,
            from: $from,
            to: $to,
            nights: $nights,
            adults: $adults,
            children: $children,
            limit: $altLimit,
            strictLocation: false,
            regionOverride: $this->clean($inquiry->region),
            log: $log
        );

        $log['steps'][] = ['step' => 'alternatives_count', 'count' => $alternatives->count()];

        $log['reason'] = $alternatives->isNotEmpty()
            ? 'no_primary_used_alternatives'
            : 'no_availability_or_price';

        return ['primary' => collect(), 'alternatives' => $alternatives, 'log' => $log];
    }
    public function findFallbackAlternatives(Inquiry $inquiry, int $limit = 5): Collection
    {
        // Ovo je fallback koji ViewInquiry očekuje.
        // Ideja: ako nema location (npr. samo "Tasos"), tretiraj region kao lokaciju.
        if (! $inquiry->date_from || ! $inquiry->date_to || ! $inquiry->adults) {
            return collect();
        }

        $from = Carbon::parse($inquiry->date_from)->startOfDay();
        $to   = Carbon::parse($inquiry->date_to)->startOfDay();

        $nights = (int) ($inquiry->nights ?: $from->diffInDays($to));
        if ($nights <= 0) {
            return collect();
        }

        $adults   = (int) $inquiry->adults;
        $children = (int) ($inquiry->children ?: 0);

        // 1) Ako nema location, uzmi region kao lokaciju (Tasos, Lefkada, itd.)
        $locCandidate = $this->clean($inquiry->location) ?: $this->clean($inquiry->region);

        // Napravi "soft clone" upita (ne snimamo u bazu)
        $tmp = clone $inquiry;
        if ($locCandidate) {
            $tmp->location = $locCandidate;
        }

        // 2) Pokušaj: strictLocation=true ali BEZ region filtera (jer "Tasos" često nije region u tvojoj bazi nego destinacija)
        $dummyLog = ['steps' => []];

        $strict = $this->runMatch(
            inquiry: $tmp,
            from: $from,
            to: $to,
            nights: $nights,
            adults: $adults,
            children: $children,
            limit: $limit,
            strictLocation: true,
            regionOverride: null, // <- ključ: ignoriši region
            log: $dummyLog
        );

        if ($strict->isNotEmpty()) {
            return $strict;
        }

        // 3) Ako ni to ne da ništa: opušteno po celoj bazi (i dalje sort/ai_order radi svoje)
        $relaxed = $this->runMatch(
            inquiry: $tmp,
            from: $from,
            to: $to,
            nights: $nights,
            adults: $adults,
            children: $children,
            limit: $limit,
            strictLocation: false,
            regionOverride: null,
            log: $dummyLog
        );

        return $relaxed;
    }

    private function runMatch(
        Inquiry $inquiry,
        Carbon $from,
        Carbon $to,
        int $nights,
        int $adults,
        int $children,
        int $limit,
        bool $strictLocation,
        ?string $regionOverride,
        array &$log
    ): Collection {
        $region = $this->clean($regionOverride);

        /** @var Builder $hotelsQ */
        $hotelsQ = Hotel::query()
            ->aiEligible()
            ->aiOrdered()
            ->when($region, fn ($q) => $q->matchRegion($region))
            ->with(['rooms', 'location'])
            ->limit(200);

        // --- Location filter logic ---
        if ($strictLocation) {
            $locRaw = $this->clean($inquiry->location);

            // 1) pokušaj da pronađeš canonical location_id iz pt_locations (najtačnije!)
            $resolved = $this->resolveLocationFromDb($locRaw);

            $log['steps'][] = [
                'step' => 'location_resolve',
                'strictLocation' => true,
                'loc_raw' => $locRaw,
                'resolved' => $resolved,
            ];

            if (! empty($resolved['location_id'])) {
                // ✅ NAJTAČNIJE: hotel_city == pt_locations.id
                $hotelsQ->where('hotel_city', (string) $resolved['location_id']);

                $log['steps'][] = [
                    'step' => 'location_filter_applied',
                    'mode' => 'hotel_city_id',
                    'location_id' => $resolved['location_id'],
                ];
            } else {
                // 2) fallback: needles + robust LIKE preko relacije + fallback polja
                $needles = $this->expandLocationNeedles($locRaw, $resolved['canonical'] ?? []);

                $log['steps'][] = [
                    'step' => 'location_filter_setup',
                    'mode' => 'needles_like',
                    'needles' => $needles,
                    'pt_locations_cols' => self::$locationTextColumns,
                ];

                if (! empty($needles)) {
                    $this->applyRobustLocationFilter($hotelsQ, $needles, self::$locationTextColumns);
                }
            }
        }

        $hotels = $hotelsQ->get();

        $log['steps'][] = [
            'step'           => 'hotels_loaded',
            'strictLocation' => $strictLocation,
            'region_used'    => $region,
            'hotels_count'   => $hotels->count(),
        ];

        if ($hotels->isEmpty()) {
            return collect();
        }

        $results = collect();

        $roomsTotal = 0;
        $fitsPass = 0;
        $availabilityMiss = 0;
        $priceMiss = 0;
        $priceZeroMiss = 0;
        $budgetMiss = 0;

        foreach ($hotels as $hotel) {
            foreach ($hotel->rooms as $room) {
                $roomsTotal++;

                if (! $this->roomFits($room, $adults, $children, $nights)) {
                    continue;
                }
                $fitsPass++;

                if (! $this->roomIsAvailableForRange((int) $room->room_id, $from, $to)) {
                    $availabilityMiss++;
                    continue;
                }

                $total = $this->calculateTotalForRoom((int) $room->room_id, $from, $to, $adults, $children);

                if ($total <= 0) {
                    $hasRows = $this->getPriceRowsForRoom((int) $room->room_id)->isNotEmpty();
                    if (! $hasRows) $priceMiss++;
                    else $priceZeroMiss++;
                    continue;
                }

                if ($inquiry->budget_max && $total > (int) $inquiry->budget_max) {
                    $budgetMiss++;
                    continue;
                }

                $results->push([
                    'hotel' => $hotel,
                    'room'  => $room,
                    'price' => [
                        'total'     => round($total, 2),
                        'per_night' => round($total / $nights, 2),
                        'nights'    => $nights,
                    ],
                    '_match' => $strictLocation ? 'primary' : 'alternative',
                ]);

                if ($results->count() >= $limit) {
                    break 2;
                }
            }
        }

        $log['steps'][] = [
            'step' => 'filter_stats',
            'strictLocation' => $strictLocation,
            'rooms_total' => $roomsTotal,
            'fits_pass' => $fitsPass,
            'availability_miss' => $availabilityMiss,
            'price_miss' => $priceMiss,
            'price_zero_miss' => $priceZeroMiss,
            'budget_miss' => $budgetMiss,
            'results' => $results->count(),
        ];

        return $results;
    }

    /**
     * ✅ Filter preko pt_locations relacije + (opciono) fallback preko hotel polja kad hotel_city NULL
     * Radi sa needle-ovima (razne varijante).
     */
    private function applyRobustLocationFilter(Builder $hotelsQ, array $needles, array $locationCols): void
    {
        $needles = array_values(array_unique(array_filter(array_map(fn ($x) => trim((string) $x), $needles))));

        // zaštita: preskoči prekratke needle-ove (mnogo false-positive)
        $needles = array_values(array_filter($needles, fn ($s) => mb_strlen($s) >= 3));

        if (empty($needles)) return;

        $hotelsQ->where(function ($root) use ($needles, $locationCols) {
            // 1) normalno: whereHas(location)
            $root->whereHas('location', function ($q) use ($needles, $locationCols) {
                $q->where(function ($qq) use ($needles, $locationCols) {
                    foreach ($needles as $loc) {
                        $locLower = mb_strtolower($loc);
                        foreach ($locationCols as $col) {
                            // case-insensitive (sigurno)
                            $qq->orWhereRaw("LOWER($col) LIKE ?", ["%{$locLower}%"]);
                        }
                    }
                });
            });

            // 2) fallback kad nemamo hotel_city/location relaciju
            $root->orWhere(function ($q2) use ($needles) {
                $q2->whereNull('hotel_city')
                    ->where(function ($qq2) use ($needles) {
                        foreach ($needles as $loc) {
                            $locLower = mb_strtolower($loc);
                            $qq2->orWhereRaw('LOWER(mesto) LIKE ?', ["%{$locLower}%"])
                                ->orWhereRaw('LOWER(hotel_map_city) LIKE ?', ["%{$locLower}%"]);
                        }
                    });
            });
        });
    }

    private function roomFits(Room $room, int $adults, int $children, int $nights): bool
    {
        $roomAdults   = (int) ($room->room_adults ?? 0);
        $roomChildren = (int) ($room->room_children ?? 0);
        $minStay      = (int) ($room->room_min_stay ?? 1);

        return $adults <= $roomAdults
            && $children <= $roomChildren
            && $nights >= $minStay;
    }

    private function roomIsAvailableForRange(int $roomId, Carbon $from, Carbon $to): bool
    {
        $baseYear = now()->year;

        $cursor = $from->copy();
        $totalDays = 0;
        $okDays = 0;

        while ($cursor->lt($to)) {
            $yearFlag = $cursor->year - $baseYear; // 0 ili 1
            if ($yearFlag < 0 || $yearFlag > 1) {
                return false;
            }

            $m = (int) $cursor->month;
            $day = (int) $cursor->day;
            $col = 'd' . $day;

            $row = RoomAvailability::query()
                ->where('room_id', $roomId)
                ->where('y', $yearFlag)
                ->where('m', $m)
                ->first();

            if (! $row) {
                return false;
            }

            $totalDays++;
            $val = (int) ($row->{$col} ?? 0);
            if ($val > 0) {
                $okDays++;
            }

            $cursor->addDay();
        }

        return $totalDays > 0 && ($okDays / $totalDays) >= 0.6;
    }

    private function calculateTotalForRoom(int $roomId, Carbon $from, Carbon $to, int $adults, int $children): float
    {
        $rows = $this->getPriceRowsForRoom($roomId);
        if ($rows->isEmpty()) {
            return 0.0;
        }

        $total = 0.0;
        $cursor = $from->copy();

        while ($cursor->lt($to)) {
            $priceRow = $this->bestPriceRowForDay($rows, $cursor, $adults, $children);
            if (! $priceRow) {
                return 0.0;
            }

            $dayKey = strtolower($cursor->format('D')); // mon..sun
            $val = (float) ($priceRow->{$dayKey} ?? 0);

            if ($val <= 0) {
                return 0.0;
            }

            $total += $val;
            $cursor->addDay();
        }

        return $total;
    }

    private function bestPriceRowForDay(Collection $rows, Carbon $day, int $adults, int $children): ?RoomPrice
    {
        $m = (int) $day->month;
        $d = (int) $day->day;

        $candidates = $rows->filter(function (RoomPrice $r) use ($m, $d) {
            $from = Carbon::parse($r->date_from);
            $to   = Carbon::parse($r->date_to);

            $fm = (int) $from->month; $fd = (int) $from->day;
            $tm = (int) $to->month;   $td = (int) $to->day;

            // normal interval
            if ($fm < $tm || ($fm === $tm && $fd <= $td)) {
                if ($m < $fm || ($m === $fm && $d < $fd)) return false;
                if ($m > $tm || ($m === $tm && $d > $td)) return false;
                return true;
            }

            // wrap-around (sezona prelazi godinu)
            $inEnd   = ($m > $fm) || ($m === $fm && $d >= $fd);
            $inStart = ($m < $tm) || ($m === $tm && $d <= $td);
            return $inEnd || $inStart;
        });

        if ($candidates->isEmpty()) {
            return null;
        }

        $exact = $candidates
            ->where('adults', $adults)
            ->where('children', $children)
            ->sortByDesc(fn ($r) => (int) ($r->is_default ?? 0))
            ->first();

        if ($exact) return $exact;

        return $candidates
            ->sortByDesc(fn ($r) => (int) ($r->is_default ?? 0))
            ->first();
    }

    private function getPriceRowsForRoom(int $roomId): Collection
    {
        if (isset($this->pricesCache[$roomId])) {
            return $this->pricesCache[$roomId];
        }

        $rows = RoomPrice::query()
            ->where('room_id', $roomId)
            ->get();

        return $this->pricesCache[$roomId] = $rows;
    }

    private function clean(?string $v): ?string
    {
        $v = trim((string) $v);
        return $v !== '' ? $v : null;
    }

    /**
     * Ručni aliasi (nije obavezno, ali ostavi za “poznate” slučajeve)
     */
    private function locationAliases(): array
    {
        return [
            'jerisos' => ['ierissos', 'ierissos, athos', 'ierissos athos'],
        ];
    }

    /**
     * “Rešenje za celu Grčku”:
     * Pokušaj da nađeš canonical location_id i canonical tekstove iz pt_locations.
     *
     * Vraca:
     * [
     *   'location_id' => int|null,
     *   'canonical'   => [strings...],   // location/title/h1/region/link...
     *   'matched_by'  => 'exact'|'like'|null,
     * ]
     */
    private function resolveLocationFromDb(?string $loc): array
    {
        $loc = $this->clean($loc);
        if (! $loc) {
            return ['location_id' => null, 'canonical' => [], 'matched_by' => null];
        }

        $needleRaw = trim($loc);
        $needle = mb_strtolower($needleRaw);

        // zaštita: prekratko = previše šuma
        if (mb_strlen($needle) < 3) {
            return ['location_id' => null, 'canonical' => [], 'matched_by' => 'too_short'];
        }

        // probaj i slug varijantu: "Ierissos, Athos" -> "ierissos-athos"
        $slug = Str::of($needle)
            ->replace([',', '.', ';', ':'], ' ')
            ->squish()
            ->replace(' ', '-')
            ->toString();

        // 1) EXACT-ish (najpreciznije)
        $row = DB::connection('grcka')->table('pt_locations')
            ->select('id', 'location', 'title', 'h1', 'region', 'link', 'desc')
            ->whereRaw('LOWER(link) = ?', [$needle])
            ->orWhereRaw('LOWER(link) = ?', [$slug])
            ->orWhereRaw('LOWER(location) = ?', [$needle])
            ->orWhereRaw('LOWER(title) = ?', [$needle])
            ->orWhereRaw('LOWER(h1) = ?', [$needle])
            ->first();

        if ($row) {
            return [
                'location_id' => (int) $row->id,
                'canonical'   => $this->canonicalStringsFromLocationRow($row),
                'matched_by'  => 'exact',
            ];
        }

        // 2) LIKE (kontrolisano) – uzmi top 1, ali izvuci canonical stringove
        $row2 = DB::connection('grcka')->table('pt_locations')
            ->select('id', 'location', 'title', 'h1', 'region', 'link', 'desc')
            ->where(function ($q) use ($needle) {
                foreach (self::$locationTextColumns as $col) {
                    $q->orWhereRaw("LOWER($col) LIKE ?", ["%{$needle}%"]);
                }
            })
            // “bolji” pogodak: preferiraj kraći link i da počinje needle-om
            ->orderByRaw('CASE WHEN LOWER(link) LIKE ? THEN 0 ELSE 1 END', ["{$needle}%"])
            ->orderByRaw('LENGTH(link) ASC')
            ->limit(1)
            ->first();

        if ($row2) {
            return [
                'location_id' => (int) $row2->id,
                'canonical'   => $this->canonicalStringsFromLocationRow($row2),
                'matched_by'  => 'like',
            ];
        }

        return ['location_id' => null, 'canonical' => [], 'matched_by' => null];
    }

    private function canonicalStringsFromLocationRow(object $row): array
    {
        $out = [];

        foreach (['location','title','h1','region','link','desc'] as $k) {
            $v = trim((string) ($row->{$k} ?? ''));
            if ($v !== '') {
                $out[] = $v;

                // link tokeni: ierissos-athos => "ierissos athos", ["ierissos","athos"]
                if ($k === 'link') {
                    $out[] = str_replace('-', ' ', $v);
                    foreach (array_filter(explode('-', $v)) as $p) {
                        $out[] = $p;
                    }
                }
            }
        }

        // normalize
        $out = array_map(fn ($s) => trim((string) $s), $out);
        $out = array_filter($out, fn ($s) => $s !== '');
        $out = array_unique($out);

        return array_values($out);
    }

    /**
     * Vrati needle-ove: original + aliasi + canonical iz DB + token varijante.
     * $canonicalFromDb (ako je već resolved) prosledimo da ne radimo dupli DB lookup.
     */
    private function expandLocationNeedles(?string $loc, array $canonicalFromDb = []): array
    {
        $loc = $this->clean($loc);
        if (! $loc) return [];

        $base = trim($loc);
        $out  = [$base];

        // 1) ručni aliasi (opciono)
        $t = mb_strtolower($base);
        $aliases = $this->locationAliases();
        if (isset($aliases[$t])) {
            foreach ($aliases[$t] as $a) {
                $out[] = $a;
            }
        }

        // 2) canonical iz DB (ako postoji)
        foreach ($canonicalFromDb as $c) {
            $out[] = $c;
        }

        // 3) tokenizacija: razbij na delove (zarez, crtica, slash)
        foreach ($this->tokenizeLocation($base) as $tok) {
            $out[] = $tok;
        }

        // 4) ukloni navodnike/čudne apostrofe
        $out[] = str_replace(['’', "'", '"'], '', $base);

        // 5) normalize + dedupe + remove too short
        $out = array_map(fn ($s) => trim((string) $s), $out);
        $out = array_filter($out, fn ($s) => $s !== '');
        $out = array_unique($out);
        $out = array_filter($out, fn ($s) => mb_strlen($s) >= 3);

        return array_values($out);
    }

    private function tokenizeLocation(string $s): array
    {
        $s = mb_strtolower(trim($s));
        if ($s === '') return [];

        // zameni separatore u space
        $s = str_replace([',', ';', '/', '\\', '|', '.', ':', '(', ')', '[', ']', '{', '}', "\n", "\r", "\t"], ' ', $s);
        $s = str_replace(['-', '_'], ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s) ?: $s;
        $s = trim($s);

        $parts = array_filter(explode(' ', $s));

        // dodaj i “skupi” oblik bez space (nekad pomaže)
        $out = [];
        foreach ($parts as $p) {
            $out[] = $p;
        }

        if (count($parts) >= 2) {
            $out[] = implode(' ', $parts);
        }

        return array_values(array_unique(array_filter($out)));
    }
}
