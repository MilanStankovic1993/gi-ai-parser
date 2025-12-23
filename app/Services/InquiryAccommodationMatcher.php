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
    private static array $locationTextColumns = ['location', 'title', 'h1', 'region', 'link', 'desc'];

    private array $pricesCache = [];
    private array $availabilityMonthCache = [];

    public function match(Inquiry $inquiry, int $limit = 5): Collection
    {
        $out = $this->matchWithAlternatives($inquiry, $limit, $limit);
        return collect($out['primary'] ?? []);
    }

    public function matchWithAlternatives(Inquiry $inquiry, int $primaryLimit = 5, int $altLimit = 5): array
    {
        $log = [
            'input' => [
                'date_from'  => $inquiry->date_from ? Carbon::parse($inquiry->date_from)->toDateString() : null,
                'date_to'    => $inquiry->date_to ? Carbon::parse($inquiry->date_to)->toDateString() : null,
                'nights'     => $inquiry->nights,
                'region'     => $inquiry->region,
                'location'   => $inquiry->location,
                'budget_max' => $inquiry->budget_max,
                'intent'     => $inquiry->intent ?? null,
                'date_window'=> data_get($inquiry, 'travel_time.date_window'),
                'travel_nights' => data_get($inquiry, 'travel_time.nights'),
            ],
            'date' => [
                'date_mode'       => null,  // exact|window|none
                'date_window'     => data_get($inquiry, 'travel_time.date_window'),
                'date_from_used'  => null,
                'date_to_used'    => null,
                'date_try_count'  => 0,
                'candidates'      => [],
            ],
            'reason' => null,
            'steps'  => [],
            'units'  => [],
        ];

        // ✅ Build units (source of truth)
        $units = $this->getUnitsForInquiry($inquiry);
        if (empty($units)) {
            $log['reason'] = 'no_units';
            return ['primary' => [], 'alternatives' => [], 'log' => $log];
        }

        // ✅ Resolve nights (prefer explicit nights; fallback to travel_time.nights)
        $nights = $this->resolveNights($inquiry);
        if ($nights <= 0) {
            $log['reason'] = 'invalid_nights';
            return ['primary' => [], 'alternatives' => [], 'log' => $log];
        }

        // ✅ CASE 1: Exact dates available -> normal flow
        if ($inquiry->date_from && ($inquiry->date_to || $nights > 0)) {
            $from = Carbon::parse($inquiry->date_from)->startOfDay();
            $to   = $inquiry->date_to
                ? Carbon::parse($inquiry->date_to)->startOfDay()
                : $from->copy()->addDays($nights)->startOfDay();

            $log['date']['date_mode']      = 'exact';
            $log['date']['date_from_used'] = $from->toDateString();
            $log['date']['date_to_used']   = $to->toDateString();

            return $this->matchForRange($inquiry, $units, $from, $to, $nights, $primaryLimit, $altLimit, $log);
        }

        // ✅ CASE 2: No exact dates, but date_window exists -> try candidates inside window
        $candidates = $this->resolveDateStartCandidatesFromWindow($inquiry, stepDays: 3, maxTries: 14);
        $log['date']['candidates'] = array_map(fn (Carbon $c) => $c->toDateString(), $candidates);

        if (! empty($candidates)) {
            $log['date']['date_mode'] = 'window';

            $best = null; // keep best attempt (most primary results)
            foreach ($candidates as $dt) {
                $from = $dt->copy()->startOfDay();
                $to   = $from->copy()->addDays($nights)->startOfDay();

                $log['date']['date_try_count']++;

                $attemptLog = $log;
                $attemptLog['date']['date_from_used'] = $from->toDateString();
                $attemptLog['date']['date_to_used']   = $to->toDateString();

                $attempt = $this->matchForRange($inquiry, $units, $from, $to, $nights, $primaryLimit, $altLimit, $attemptLog);

                $primaryCount = count($attempt['primary'] ?? []);
                $altCount     = count($attempt['alternatives'] ?? []);

                // čim imamo bar nešto primarno -> vraćamo odmah (najintuitivnije za klijenta)
                if ($primaryCount > 0) {
                    return $attempt;
                }

                // ako nemamo primary, ali imamo alts, pamti najbolji pokušaj
                if ($best === null) {
                    $best = $attempt;
                } else {
                    $bestAlt = count($best['alternatives'] ?? []);
                    if ($altCount > $bestAlt) $best = $attempt;
                }
            }

            // ništa nije dalo primary; vrati najbolji alt attempt (ako ga ima)
            if ($best !== null) {
                $bestLog = $best['log'] ?? [];
                $bestLog['reason'] = (count($best['alternatives'] ?? []) > 0)
                    ? 'window_no_primary_used_alternatives'
                    : 'window_no_availability_or_price';
                $best['log'] = $bestLog;

                return $best;
            }
        }

        // ✅ CASE 3: No exact and no date_window -> cannot offer (ali razlog je jasan)
        $log['date']['date_mode'] = 'none';
        $log['reason'] = 'missing_dates_and_date_window';
        return ['primary' => [], 'alternatives' => [], 'log' => $log];
    }

    /**
     * Glavni “engine” za konkretan datum range.
     * Vraća ARRAYS (ne Collection) radi stabilnog JSON / payload snimanja.
     */
    private function matchForRange(
        Inquiry $inquiry,
        array $units,
        Carbon $from,
        Carbon $to,
        int $nights,
        int $primaryLimit,
        int $altLimit,
        array $log
    ): array {
        // “source of truth” polja u logu
        $log['steps'][] = [
            'step' => 'range',
            'from' => $from->toDateString(),
            'to'   => $to->toDateString(),
            'nights'=> $nights,
        ];

        $allPrimary = collect();
        $allAlt     = collect();

        foreach ($units as $unit) {
            $unitIndex = (int) ($unit['unit_index'] ?? 1);

            $pg = is_array($unit['party_group'] ?? null) ? $unit['party_group'] : [];
            $adults   = (int) ($pg['adults'] ?? 0);
            $children = (int) ($pg['children'] ?? 0);

            if ($adults <= 0) {
                $log['units'][] = ['unit_index' => $unitIndex, 'reason' => 'missing_adults'];
                continue;
            }

            $uLog = ['unit_index' => $unitIndex, 'reason' => null, 'steps' => []];

            // 0) HOTEL-NAME-FIRST (po unit-u)
            $byName = $this->matchByRequestedHotels(
                inquiry: $inquiry,
                from: $from,
                to: $to,
                nights: $nights,
                adults: $adults,
                children: $children,
                limit: $primaryLimit,
                log: $uLog,
                unit: $unit
            );

            if ($byName->isNotEmpty()) {
                $uLog['reason'] = 'found_by_requested_hotels';
                $log['units'][] = $uLog;

                $allPrimary = $allPrimary->merge(
                    $byName->map(fn ($row) => array_merge($row, ['unit_index' => $unitIndex]))
                );
                continue;
            }

            // 1) PRIMARY – strogo mesto
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
                log: $uLog
            );

            $uLog['steps'][] = ['step' => 'primary_count', 'count' => $primary->count()];

            if ($primary->isNotEmpty()) {
                $uLog['reason'] = 'found_primary';
                $log['units'][] = $uLog;

                $allPrimary = $allPrimary->merge(
                    $primary->map(fn ($row) => array_merge($row, ['unit_index' => $unitIndex]))
                );
                continue;
            }

            // 2) ALTERNATIVES – opušteno
            $alts = $this->runMatch(
                inquiry: $inquiry,
                from: $from,
                to: $to,
                nights: $nights,
                adults: $adults,
                children: $children,
                limit: $altLimit,
                strictLocation: false,
                regionOverride: $this->clean($inquiry->region),
                log: $uLog
            );

            $uLog['steps'][] = ['step' => 'alternatives_count', 'count' => $alts->count()];
            $uLog['reason'] = $alts->isNotEmpty() ? 'no_primary_used_alternatives' : 'no_availability_or_price';

            $log['units'][] = $uLog;

            if ($alts->isNotEmpty()) {
                $allAlt = $allAlt->merge(
                    $alts->map(fn ($row) => array_merge($row, ['unit_index' => $unitIndex]))
                );
            }
        }

        $log['reason'] = $allPrimary->isNotEmpty()
            ? 'found_primary'
            : ($allAlt->isNotEmpty() ? 'no_primary_used_alternatives' : 'no_availability_or_price');

        return [
            'primary'      => $allPrimary->values()->all(),
            'alternatives' => $allAlt->values()->all(),
            'log'          => $log,
        ];
    }

    private function resolveNights(Inquiry $inquiry): int
    {
        $n = (int) ($inquiry->nights ?? 0);
        if ($n > 0) return $n;

        $tn = (int) data_get($inquiry, 'travel_time.nights', 0);
        return $tn > 0 ? $tn : 0;
    }

    /**
     * Ako nema date_from/date_to, ali ima travel_time.date_window, napravi kandidate start datuma.
     */
    private function resolveDateStartCandidatesFromWindow(Inquiry $inquiry, int $stepDays = 3, int $maxTries = 14): array
    {
        $window = data_get($inquiry, 'travel_time.date_window');
        if (! is_array($window)) return [];

        $from = $window['from'] ?? null;
        $to   = $window['to'] ?? null;
        if (! $from || ! $to) return [];

        try {
            $start = Carbon::parse($from)->startOfDay();
            $end   = Carbon::parse($to)->startOfDay();
        } catch (\Throwable) {
            return [];
        }

        if ($end->lt($start)) return [];

        $out = [];
        $cur = $start->copy();
        $tries = 0;

        while ($cur->lte($end) && $tries < $maxTries) {
            $out[] = $cur->copy();
            $cur->addDays($stepDays);
            $tries++;
        }

        // probaj i krajnju tačku ako nije već uključena
        if (! empty($out) && ! $out[count($out) - 1]->equalTo($end)) {
            $out[] = $end->copy();
        }

        return $out;
    }

    private function getUnitsForInquiry(Inquiry $inquiry): array
    {
        $units = $inquiry->units;

        if (is_string($units)) {
            $decoded = json_decode($units, true);
            $units = is_array($decoded) ? $decoded : [];
        }

        if (is_array($units) && ! empty($units)) {
            // osiguraj unit_index ako ga nema
            return array_values(array_map(function ($u, $i) {
                $u = is_array($u) ? $u : [];
                if (! isset($u['unit_index'])) $u['unit_index'] = $i + 1;
                return $u;
            }, $units, array_keys($units)));
        }

        // fallback: build from party.groups
        $party = is_array($inquiry->party) ? $inquiry->party : [];
        $groups = $party['groups'] ?? [];

        if (is_string($groups)) {
            $decoded = json_decode($groups, true);
            $groups = is_array($decoded) ? $decoded : [];
        }

        $out = [];
        foreach (is_array($groups) ? $groups : [] as $i => $g) {
            if (! is_array($g)) continue;

            $out[] = [
                'unit_index' => $i + 1,
                'party_group' => [
                    'adults' => $g['adults'] ?? null,
                    'children' => $g['children'] ?? null,
                    'children_ages' => $g['children_ages'] ?? [],
                    'requirements' => $g['requirements'] ?? [],
                ],
                'property_candidates' => [],
                'wishes_override' => null,
            ];
        }

        return $out;
    }

    /**
     * HOTEL-NAME MATCH (po unit-u)
     * - prvo pokušaj: unit.property_candidates
     * - pa global property_candidates / extraction_debug / raw_message kao fallback
     */
    private function matchByRequestedHotels(
        Inquiry $inquiry,
        Carbon $from,
        Carbon $to,
        int $nights,
        int $adults,
        int $children,
        int $limit,
        array &$log,
        array $unit
    ): Collection {
        $names = [];

        // 0) unit property_candidates (najbitnije)
        $pc = $unit['property_candidates'] ?? [];
        if (is_string($pc)) {
            $decoded = json_decode($pc, true);
            $pc = is_array($decoded) ? $decoded : [];
        }
        if (is_array($pc)) {
            foreach ($pc as $row) {
                $q = is_array($row) ? trim((string) ($row['query'] ?? '')) : trim((string) $row);
                if ($q !== '') $names[] = $q;
            }
        }

        // 1) global (tvoj stari fallback)
        if (empty($names) && is_array($inquiry->extraction_debug ?? null)) {
            $req = $inquiry->extraction_debug['requested_hotels'] ?? null;
            if (is_array($req)) {
                foreach ($req as $n) {
                    $n = trim((string) $n);
                    if ($n !== '') $names[] = $n;
                }
            }
        }

        // 2) raw_message heuristic
        if (empty($names)) {
            $raw = mb_strtolower((string) $inquiry->raw_message);
            if (preg_match('/zanimaju\s*:\s*(.+)$/iu', $raw, $m)) {
                $parts = preg_split('/,|\n|;|\t|\s{2,}/u', $m[1]) ?: [];
                foreach ($parts as $p) {
                    $p = trim($p);
                    if (mb_strlen($p) >= 3) $names[] = $p;
                }
            }
        }

        $names = array_values(array_unique(array_filter(array_map(fn ($s) => trim((string) $s), $names))));
        $log['steps'][] = ['step' => 'requested_hotels', 'names' => $names];

        if (empty($names)) return collect();

        $hotels = Hotel::query()
            ->aiEligible()
            ->aiOrdered()
            ->where(function ($q) use ($names) {
                foreach ($names as $name) {
                    $needle = mb_strtolower($name);
                    $q->orWhereRaw('LOWER(`hotel_title`) LIKE ?', ['%' . $needle . '%'])
                      ->orWhereRaw('LOWER(`title`) LIKE ?', ['%' . $needle . '%']);
                }
            })
            ->with(['rooms', 'location'])
            ->limit(50)
            ->get();

        $log['steps'][] = ['step' => 'requested_hotels_db_hits', 'count' => $hotels->count()];

        if ($hotels->isEmpty()) return collect();

        $results = collect();

        foreach ($hotels as $hotel) {
            foreach ($hotel->rooms as $room) {
                if (! $this->roomFits($room, $adults, $children, $nights)) continue;
                if (! $this->roomIsAvailableForRange((int) $room->room_id, $from, $to)) continue;

                $total = $this->calculateTotalForRoom((int) $room->room_id, $from, $to, $adults, $children);
                if ($total <= 0) continue;

                if ($inquiry->budget_max && $total > (int) $inquiry->budget_max) continue;

                $results->push([
                    'hotel' => $hotel,
                    'room'  => $room,
                    'price' => [
                        'total'     => round($total, 2),
                        'per_night' => round($total / $nights, 2),
                        'nights'    => $nights,
                    ],
                    '_match' => 'requested_hotels',
                ]);

                if ($results->count() >= $limit) break 2;
            }
        }

        $log['steps'][] = ['step' => 'requested_hotels_results', 'count' => $results->count()];

        return $results;
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

        $hotelsQ = Hotel::query()
            ->aiEligible()
            ->aiOrdered()
            ->when($region, fn ($q) => $q->matchRegion($region))
            ->with(['rooms', 'location'])
            ->limit(200);

        if ($strictLocation) {
            $locRaw = $this->clean($inquiry->location);
            $resolved = $this->resolveLocationFromDb($locRaw);

            $log['steps'][] = [
                'step' => 'location_resolve',
                'strictLocation' => true,
                'loc_raw' => $locRaw,
                'resolved' => $resolved,
            ];

            if (! empty($resolved['location_id'])) {
                $hotelsQ->where('hotel_city', (string) $resolved['location_id']);
                $log['steps'][] = [
                    'step' => 'location_filter_applied',
                    'mode' => 'hotel_city_id',
                    'location_id' => $resolved['location_id'],
                ];
            } else {
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

        if ($hotels->isEmpty()) return collect();

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

                if (! $this->roomFits($room, $adults, $children, $nights)) continue;
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

                if ($results->count() >= $limit) break 2;
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

    private function applyRobustLocationFilter(Builder $hotelsQ, array $needles, array $locationCols): void
    {
        $needles = array_values(array_unique(array_filter(array_map(fn ($x) => trim((string) $x), $needles))));
        $needles = array_values(array_filter($needles, fn ($s) => mb_strlen($s) >= 3));
        if (empty($needles)) return;

        $hotelsQ->where(function ($root) use ($needles, $locationCols) {
            $root->whereHas('location', function ($q) use ($needles, $locationCols) {
                $q->where(function ($qq) use ($needles, $locationCols) {
                    foreach ($needles as $loc) {
                        $locLower = mb_strtolower($loc);
                        foreach ($locationCols as $col) {
                            $qq->orWhereRaw($this->lowerCol($col) . ' LIKE ?', ["%{$locLower}%"]);
                        }
                    }
                });
            });

            $root->orWhere(function ($q2) use ($needles) {
                $q2->whereNull('hotel_city')
                    ->where(function ($qq2) use ($needles) {
                        foreach ($needles as $loc) {
                            $locLower = mb_strtolower($loc);
                            $qq2->orWhereRaw($this->lowerSimpleCol('mesto') . ' LIKE ?', ["%{$locLower}%"])
                                ->orWhereRaw($this->lowerSimpleCol('hotel_map_city') . ' LIKE ?', ["%{$locLower}%"]);
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
            $yearFlag = $cursor->year - $baseYear;
            if ($yearFlag < 0 || $yearFlag > 1) return false;

            $m = (int) $cursor->month;
            $day = (int) $cursor->day;
            $col = 'd' . $day;

            $row = $this->getAvailabilityRowForRoomMonth($roomId, $yearFlag, $m);
            if (! $row) return false;

            $totalDays++;
            $val = (int) ($row->{$col} ?? 0);
            if ($val > 0) $okDays++;

            $cursor->addDay();
        }

        return $totalDays > 0 && ($okDays / $totalDays) >= 0.6;
    }

    private function getAvailabilityRowForRoomMonth(int $roomId, int $yearFlag, int $month): ?RoomAvailability
    {
        if (isset($this->availabilityMonthCache[$roomId][$yearFlag][$month])) {
            return $this->availabilityMonthCache[$roomId][$yearFlag][$month];
        }

        $row = RoomAvailability::query()
            ->where('room_id', $roomId)
            ->where('y', $yearFlag)
            ->where('m', $month)
            ->first();

        $this->availabilityMonthCache[$roomId][$yearFlag][$month] = $row ?: null;
        return $row ?: null;
    }

    private function calculateTotalForRoom(int $roomId, Carbon $from, Carbon $to, int $adults, int $children): float
    {
        $rows = $this->getPriceRowsForRoom($roomId);
        if ($rows->isEmpty()) return 0.0;

        $total = 0.0;
        $cursor = $from->copy();

        while ($cursor->lt($to)) {
            $priceRow = $this->bestPriceRowForDay($rows, $cursor, $adults, $children);
            if (! $priceRow) return 0.0;

            $dayKey = strtolower($cursor->format('D'));
            $val = (float) ($priceRow->{$dayKey} ?? 0);
            if ($val <= 0) return 0.0;

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
            if (! $r->date_from || ! $r->date_to) return false;

            $from = Carbon::parse($r->date_from);
            $to   = Carbon::parse($r->date_to);

            $fm = (int) $from->month;
            $fd = (int) $from->day;
            $tm = (int) $to->month;
            $td = (int) $to->day;

            if ($fm < $tm || ($fm === $tm && $fd <= $td)) {
                if ($m < $fm || ($m === $fm && $d < $fd)) return false;
                if ($m > $tm || ($m === $tm && $d > $td)) return false;
                return true;
            }

            $inEnd   = ($m > $fm) || ($m === $fm && $d >= $fd);
            $inStart = ($m < $tm) || ($m === $tm && $d <= $td);
            return $inEnd || $inStart;
        });

        if ($candidates->isEmpty()) return null;

        $exact = $candidates
            ->where('adults', $adults)
            ->where('children', $children)
            ->sortByDesc(fn ($r) => (int) ($r->is_default ?? 0))
            ->first();

        return $exact ?: $candidates->sortByDesc(fn ($r) => (int) ($r->is_default ?? 0))->first();
    }

    private function getPriceRowsForRoom(int $roomId): Collection
    {
        if (isset($this->pricesCache[$roomId])) return $this->pricesCache[$roomId];

        return $this->pricesCache[$roomId] = RoomPrice::query()
            ->where('room_id', $roomId)
            ->get();
    }

    private function clean(?string $v): ?string
    {
        $v = trim((string) $v);
        return $v !== '' ? $v : null;
    }

    private function locationAliases(): array
    {
        return [
            'jerisos' => ['ierissos', 'ierissos athos', 'ierissos, athos'],
            'sikia'   => ['sikia sitonia', 'sikija', 'sikija sitonija'],
        ];
    }

    private function resolveLocationFromDb(?string $loc): array
    {
        $loc = $this->clean($loc);
        if (! $loc) return ['location_id' => null, 'canonical' => [], 'matched_by' => null];

        $needleRaw = trim($loc);
        $needle = mb_strtolower($needleRaw);

        if (mb_strlen($needle) < 3) return ['location_id' => null, 'canonical' => [], 'matched_by' => 'too_short'];

        $slug = Str::of($needle)->replace([',', '.', ';', ':'], ' ')->squish()->replace(' ', '-')->toString();

        $row = DB::connection('grcka')->table('pt_locations')
            ->select('id', 'location', 'title', 'h1', 'region', 'link', 'desc')
            ->whereRaw('LOWER(`link`) = ?', [$needle])
            ->orWhereRaw('LOWER(`link`) = ?', [$slug])
            ->orWhereRaw('LOWER(`location`) = ?', [$needle])
            ->orWhereRaw('LOWER(`title`) = ?', [$needle])
            ->orWhereRaw('LOWER(`h1`) = ?', [$needle])
            ->first();

        if ($row) {
            return [
                'location_id' => (int) $row->id,
                'canonical'   => $this->canonicalStringsFromLocationRow($row),
                'matched_by'  => 'exact',
            ];
        }

        $row2 = DB::connection('grcka')->table('pt_locations')
            ->select('id', 'location', 'title', 'h1', 'region', 'link', 'desc')
            ->where(function ($q) use ($needle) {
                foreach (self::$locationTextColumns as $col) {
                    $q->orWhereRaw($this->lowerCol($col) . ' LIKE ?', ["%{$needle}%"]);
                }
            })
            ->orderByRaw('CASE WHEN LOWER(`link`) LIKE ? THEN 0 ELSE 1 END', ["{$needle}%"])
            ->orderByRaw('LENGTH(`link`) ASC')
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

        foreach (['location', 'title', 'h1', 'region', 'link', 'desc'] as $k) {
            $v = trim((string) ($row->{$k} ?? ''));
            if ($v === '') continue;

            $out[] = $v;

            if ($k === 'link') {
                $out[] = str_replace('-', ' ', $v);
                foreach (array_filter(explode('-', $v)) as $p) $out[] = $p;
            }
        }

        $out = array_map(fn ($s) => trim((string) $s), $out);
        return array_values(array_unique(array_filter($out, fn ($s) => $s !== '')));
    }

    private function expandLocationNeedles(?string $loc, array $canonicalFromDb = []): array
    {
        $loc = $this->clean($loc);
        if (! $loc) return [];

        $base = trim($loc);
        $out  = [$base];

        $t = mb_strtolower($base);
        $aliases = $this->locationAliases();
        if (isset($aliases[$t])) foreach ($aliases[$t] as $a) $out[] = $a;

        foreach ($canonicalFromDb as $c) $out[] = $c;
        foreach ($this->tokenizeLocation($base) as $tok) $out[] = $tok;

        $out[] = str_replace(['’', "'", '"'], '', $base);

        $out = array_map(fn ($s) => trim((string) $s), $out);
        return array_values(array_unique(array_filter($out, fn ($s) => $s !== '' && mb_strlen($s) >= 3)));
    }

    private function tokenizeLocation(string $s): array
    {
        $s = mb_strtolower(trim($s));
        if ($s === '') return [];

        $s = str_replace([',', ';', '/', '\\', '|', '.', ':', '(', ')', '[', ']', '{', '}', "\n", "\r", "\t"], ' ', $s);
        $s = str_replace(['-', '_'], ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s) ?: $s;
        $s = trim($s);

        $parts = array_values(array_filter(explode(' ', $s)));

        $out = $parts;
        if (count($parts) >= 2) $out[] = implode(' ', $parts);

        return array_values(array_unique(array_filter($out)));
    }

    private function lowerCol(string $col): string
    {
        if (! in_array($col, self::$locationTextColumns, true)) {
            throw new \InvalidArgumentException("Invalid column: {$col}");
        }
        return "LOWER(`{$col}`)";
    }

    private function lowerSimpleCol(string $col): string
    {
        return "LOWER(`{$col}`)";
    }
}
