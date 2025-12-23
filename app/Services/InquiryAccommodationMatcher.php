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
     * "Wide" kolone (koristi se za alternative / relaxed)
     */
    private static array $locationTextColumnsWide = ['location', 'title', 'h1', 'region', 'link'];

    /**
     * "Strict" kolone (primarno za strogu lokaciju)
     * - namerno BEZ `region` da ne bi “prosulo” na celu regiju (Sithonia, Kassandra…)
     */
    private static array $locationTextColumnsStrict = ['location', 'title', 'h1', 'link'];

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
                'date_from'     => $inquiry->date_from ? Carbon::parse($inquiry->date_from)->toDateString() : null,
                'date_to'       => $inquiry->date_to ? Carbon::parse($inquiry->date_to)->toDateString() : null,
                'nights'        => $inquiry->nights,
                'region'        => $inquiry->region,
                'location'      => $inquiry->location,
                'budget_max'    => $inquiry->budget_max,
                'intent'        => $inquiry->intent ?? null,
                'date_window'   => data_get($inquiry, 'travel_time.date_window'),
                'travel_nights' => data_get($inquiry, 'travel_time.nights'),
            ],
            'date' => [
                'date_mode'       => null, // exact|window|none
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

        $units = $this->getUnitsForInquiry($inquiry);
        if (empty($units)) {
            $log['reason'] = 'no_units';
            return ['primary' => [], 'alternatives' => [], 'log' => $log];
        }

        $nights = $this->resolveNights($inquiry);
        if ($nights <= 0) {
            $log['reason'] = 'invalid_nights';
            return ['primary' => [], 'alternatives' => [], 'log' => $log];
        }

        // CASE 1: exact
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

        // CASE 2: window
        $candidates = $this->resolveDateStartCandidatesFromWindow($inquiry, stepDays: 3, maxTries: 14);
        $log['date']['candidates'] = array_map(fn (Carbon $c) => $c->toDateString(), $candidates);

        if (! empty($candidates)) {
            $log['date']['date_mode'] = 'window';

            $best = null;
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

                if ($primaryCount > 0) return $attempt;

                if ($best === null) {
                    $best = $attempt;
                } else {
                    $bestAlt = count($best['alternatives'] ?? []);
                    if ($altCount > $bestAlt) $best = $attempt;
                }
            }

            if ($best !== null) {
                $bestLog = $best['log'] ?? [];
                $bestLog['reason'] = (count($best['alternatives'] ?? []) > 0)
                    ? 'window_no_primary_used_alternatives'
                    : 'window_no_availability_or_price';
                $best['log'] = $bestLog;

                return $best;
            }
        }

        // CASE 3: none
        $log['date']['date_mode'] = 'none';
        $log['reason'] = 'missing_dates_and_date_window';
        return ['primary' => [], 'alternatives' => [], 'log' => $log];
    }

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
        $log['steps'][] = [
            'step'   => 'range',
            'from'   => $from->toDateString(),
            'to'     => $to->toDateString(),
            'nights' => $nights,
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

            // 0) requested hotels first
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

            // 1) PRIMARY strict location
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

            // 2) ALTS relaxed
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

        if (! empty($out) && ! $out[count($out) - 1]->equalTo($end)) {
            $out[] = $end->copy();
        }

        $uniq = [];
        foreach ($out as $c) $uniq[$c->toDateString()] = $c;

        return array_values($uniq);
    }

    private function getUnitsForInquiry(Inquiry $inquiry): array
    {
        $units = $inquiry->units;

        if (is_string($units)) {
            $decoded = json_decode($units, true);
            $units = is_array($decoded) ? $decoded : [];
        }

        if (is_array($units) && ! empty($units)) {
            return array_values(array_map(function ($u, $i) {
                $u = is_array($u) ? $u : [];
                if (! isset($u['unit_index'])) $u['unit_index'] = $i + 1;
                return $u;
            }, $units, array_keys($units)));
        }

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

        if (empty($names) && is_array($inquiry->extraction_debug ?? null)) {
            $req = $inquiry->extraction_debug['requested_hotels'] ?? null;
            if (is_array($req)) {
                foreach ($req as $n) {
                    $n = trim((string) $n);
                    if ($n !== '') $names[] = $n;
                }
            }
        }

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
                      ->orWhereRaw('LOWER(`custom_name`) LIKE ?', ['%' . $needle . '%'])
                      ->orWhereRaw('LOWER(`hotel_slug`) LIKE ?', ['%' . $needle . '%'])
                      ->orWhereRaw('LOWER(`api_name`) LIKE ?', ['%' . $needle . '%']);
                }
            })
            ->with([
                'rooms' => fn ($q) => $q->select('room_id', 'room_hotel', 'room_title', 'room_adults', 'room_children', 'room_min_stay')->where('room_status', 'Yes'),
                'location' => fn ($q) => $q->select('id', 'region_id', 'region', 'location', 'title', 'h1', 'link', 'latitude', 'longitude'),
            ])
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
            ->with([
                'rooms' => fn ($q) => $q->select('room_id', 'room_hotel', 'room_title', 'room_adults', 'room_children', 'room_min_stay')->where('room_status', 'Yes'),
                'location' => fn ($q) => $q->select('id', 'region_id', 'region', 'location', 'title', 'h1', 'link', 'latitude', 'longitude'),
            ])
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
                // ✅ najtačnije: hotel_city = pt_locations.id
                $hotelsQ->where('hotel_city', (string) $resolved['location_id']);
                $log['steps'][] = [
                    'step' => 'location_filter_applied',
                    'mode' => 'hotel_city_id',
                    'location_id' => $resolved['location_id'],
                ];
            } else {
                // ✅ robust LIKE, ali STRICT:
                // - bez "region" kolone
                // - bez fallback-a na hotele sa NULL hotel_city
                $needles = $this->expandLocationNeedles($locRaw, $resolved['canonical'] ?? []);
                $log['steps'][] = [
                    'step' => 'location_filter_setup',
                    'mode' => 'needles_like_strict',
                    'needles' => $needles,
                    'pt_locations_cols' => self::$locationTextColumnsStrict,
                ];

                if (! empty($needles)) {
                    $this->applyRobustLocationFilter(
                        hotelsQ: $hotelsQ,
                        needles: $needles,
                        locationCols: self::$locationTextColumnsStrict,
                        allowNullHotelCityFallback: false
                    );
                }
            }
        } else {
            // relaxed: može wide filter ako imamo lokaciju, ali nije "must"
            $locRaw = $this->clean($inquiry->location);
            if ($locRaw) {
                $resolved = $this->resolveLocationFromDb($locRaw);

                $log['steps'][] = [
                    'step' => 'location_resolve',
                    'strictLocation' => false,
                    'loc_raw' => $locRaw,
                    'resolved' => $resolved,
                ];

                if (! empty($resolved['location_id'])) {
                    // relaxed i dalje može da favorizuje isto mesto (ne mora, ali pomaže)
                    // ne stavljamo hard constraint ovde – samo logujemo; constraint radi region + ostali filteri
                    $hotelsQ->where(function ($q) use ($resolved) {
                        $q->where('hotel_city', (string) $resolved['location_id'])
                          ->orWhereNull('hotel_city');
                    });
                    $log['steps'][] = [
                        'step' => 'location_bias_applied',
                        'mode' => 'hotel_city_id_or_null',
                        'location_id' => $resolved['location_id'],
                    ];
                } else {
                    $needles = $this->expandLocationNeedles($locRaw, $resolved['canonical'] ?? []);
                    if (! empty($needles)) {
                        $this->applyRobustLocationFilter(
                            hotelsQ: $hotelsQ,
                            needles: $needles,
                            locationCols: self::$locationTextColumnsWide,
                            allowNullHotelCityFallback: true
                        );
                        $log['steps'][] = [
                            'step' => 'location_filter_setup',
                            'mode' => 'needles_like_wide',
                            'needles' => $needles,
                            'pt_locations_cols' => self::$locationTextColumnsWide,
                        ];
                    }
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

    private function applyRobustLocationFilter(
        Builder $hotelsQ,
        array $needles,
        array $locationCols,
        bool $allowNullHotelCityFallback = true
    ): void {
        $needles = array_values(array_unique(array_filter(array_map(fn ($x) => trim((string) $x), $needles))));
        $needles = array_values(array_filter($needles, fn ($s) => mb_strlen($s) >= 3));
        if (empty($needles)) return;

        $hotelsQ->where(function ($root) use ($needles, $locationCols, $allowNullHotelCityFallback) {
            $root->whereHas('location', function ($q) use ($needles, $locationCols) {
                $q->where(function ($qq) use ($needles, $locationCols) {
                    foreach ($needles as $loc) {
                        $locLower = mb_strtolower($loc);
                        foreach ($locationCols as $col) {
                            $qq->orWhereRaw($this->lowerCol($col, $locationCols) . ' LIKE ?', ["%{$locLower}%"]);
                        }
                    }
                });
            });

            if ($allowNullHotelCityFallback) {
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
            }
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

            $dayKey = strtolower($cursor->format('D')); // mon,tue,...
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

    /**
     * Najbitnije: stabilno rešavanje lokacije:
     * 1) normalizuj (sr padeži)
     * 2) pokušaj preko pt_locations_translation (sr/rs/srp) -> loc_id
     * 3) onda pt_locations (link/location/title/h1)
     * 4) onda LIKE fallback
     */
    private function resolveLocationFromDb(?string $loc): array
    {
        $loc = $this->clean($loc);
        if (! $loc) return ['location_id' => null, 'canonical' => [], 'matched_by' => null];

        $variants = $this->normalizeLocationVariants($loc);
        $variants = array_values(array_unique(array_filter($variants, fn ($x) => mb_strlen($x) >= 3)));

        if (empty($variants)) {
            return ['location_id' => null, 'canonical' => [], 'matched_by' => 'too_short'];
        }

        // 1) translation lookup (sr/rs/srp)
        foreach ($variants as $v) {
            $needle = mb_strtolower($v);

            $tr = DB::connection('grcka')->table('pt_locations_translation')
                ->select('loc_id', 'loc_name', 'trans_lang')
                ->whereIn('trans_lang', ['sr', 'rs', 'srp'])
                ->whereRaw('LOWER(`loc_name`) LIKE ?', ['%' . $needle . '%'])
                ->orderByRaw('LENGTH(`loc_name`) ASC')
                ->limit(1)
                ->first();

            if ($tr && ! empty($tr->loc_id)) {
                $row = DB::connection('grcka')->table('pt_locations')
                    ->select('id', 'region_id', 'region', 'location', 'title', 'h1', 'link')
                    ->where('id', (int) $tr->loc_id)
                    ->first();

                if ($row) {
                    return [
                        'location_id' => (int) $row->id,
                        'canonical'   => $this->canonicalStringsFromLocationRow($row),
                        'matched_by'  => 'translation',
                    ];
                }
            }
        }

        // 2) exact-ish in pt_locations
        foreach ($variants as $v) {
            $needleRaw = trim($v);
            $needle = mb_strtolower($needleRaw);

            $slug = Str::of($needle)
                ->replace([',', '.', ';', ':'], ' ')
                ->squish()
                ->replace(' ', '-')
                ->toString();

            $row = DB::connection('grcka')->table('pt_locations')
                ->select('id', 'region_id', 'region', 'location', 'title', 'h1', 'link')
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
        }

        // 3) LIKE fallback in pt_locations
        foreach ($variants as $v) {
            $needle = mb_strtolower($v);

            $row2 = DB::connection('grcka')->table('pt_locations')
                ->select('id', 'region_id', 'region', 'location', 'title', 'h1', 'link')
                ->where(function ($q) use ($needle) {
                    $q->whereRaw('LOWER(`link`) LIKE ?', ["%{$needle}%"])
                      ->orWhereRaw('LOWER(`location`) LIKE ?', ["%{$needle}%"])
                      ->orWhereRaw('LOWER(`title`) LIKE ?', ["%{$needle}%"])
                      ->orWhereRaw('LOWER(`h1`) LIKE ?', ["%{$needle}%"]);
                    // ⚠️ namerno bez region LIKE (širi na celu regiju i vraća pogrešnu lokaciju)
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
        }

        return ['location_id' => null, 'canonical' => [], 'matched_by' => null];
    }

    /**
     * Sr padeži / varijante: "Toroniju" -> "Toroni", "Tasos" ostaje "Tasos", itd.
     */
    private function normalizeLocationVariants(string $input): array
    {
        $raw = trim($input);
        if ($raw === '') return [];

        $ascii = Str::ascii($raw);
        $baseLower = mb_strtolower($ascii);

        $out = [$raw, $ascii, $baseLower];

        if (Str::endsWith($baseLower, 'iju')) {
            $out[] = mb_substr($ascii, 0, -3) . 'i';
        }
        if (Str::endsWith($baseLower, 'ju')) {
            $out[] = mb_substr($ascii, 0, -2);
            $out[] = mb_substr($ascii, 0, -2) . 'i';
        }
        if (Str::endsWith($baseLower, 'u')) {
            $out[] = mb_substr($ascii, 0, -1);
        }
        if (Str::endsWith($baseLower, 'om')) {
            $out[] = mb_substr($ascii, 0, -2);
        }
        if (Str::endsWith($baseLower, 'a') && mb_strlen($baseLower) > 4) {
            $out[] = mb_substr($ascii, 0, -1);
        }

        foreach ($this->tokenizeLocation($ascii) as $tok) $out[] = $tok;

        $out = array_map(fn ($s) => trim((string) $s), $out);
        $out = array_values(array_unique(array_filter($out, fn ($s) => $s !== '' && mb_strlen($s) >= 3)));

        return $out;
    }

    private function canonicalStringsFromLocationRow(object $row): array
    {
        $out = [];

        // ✅ NAMERNO: bez 'region' da se ne bi proširilo na celu regiju
        foreach (['location', 'title', 'h1', 'link'] as $k) {
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

        $out = [];

        foreach ($this->normalizeLocationVariants($loc) as $v) $out[] = $v;

        $t = mb_strtolower(Str::ascii($loc));
        $aliases = $this->locationAliases();
        if (isset($aliases[$t])) foreach ($aliases[$t] as $a) $out[] = $a;

        foreach ($canonicalFromDb as $c) $out[] = $c;

        $out[] = str_replace(['’', "'", '"'], '', $loc);

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

    private function lowerCol(string $col, array $allowedCols): string
    {
        if (! in_array($col, $allowedCols, true)) {
            throw new \InvalidArgumentException("Invalid column: {$col}");
        }
        return "LOWER(`{$col}`)";
    }

    private function lowerSimpleCol(string $col): string
    {
        return "LOWER(`{$col}`)";
    }
}
