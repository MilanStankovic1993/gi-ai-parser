<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\Grcka\Hotel;
use App\Models\Grcka\Room;
use App\Models\Grcka\RoomPrice;
use App\Models\Grcka\RoomAvailability;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class InquiryAccommodationMatcher
{
    /**
     * Backward-compatible: vraća samo primary kolekciju.
     */
    public function match(Inquiry $inquiry, int $limit = 5): Collection
    {
        $out = $this->matchWithAlternatives($inquiry, $limit, $limit);

        return collect($out['primary'] ?? []);
    }

    /**
     * Produkcijski: vraća primary + alternatives + log.
     */
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

            return [
                'primary'      => collect(),
                'alternatives' => collect(),
                'log'          => $log,
            ];
        }

        $from = Carbon::parse($inquiry->date_from)->startOfDay();
        $to   = Carbon::parse($inquiry->date_to)->startOfDay();

        $nights = (int) ($inquiry->nights ?: $from->diffInDays($to));
        if ($nights <= 0) {
            $log['reason'] = 'invalid_nights';

            return [
                'primary'      => collect(),
                'alternatives' => collect(),
                'log'          => $log,
            ];
        }

        $adults   = (int) $inquiry->adults;
        $children = (int) ($inquiry->children ?: 0);

        // PRIMARY: region + location (ako postoji)
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

            return [
                'primary'      => $primary,
                'alternatives' => collect(),
                'log'          => $log,
            ];
        }

        // ALTERNATIVES: opustimo mesto (location), region ostaje ako postoji
        $alternatives = $this->runMatch(
            inquiry: $inquiry,
            from: $from,
            to: $to,
            nights: $nights,
            adults: $adults,
            children: $children,
            limit: $altLimit,
            strictLocation: false,
            regionOverride: $this->clean($inquiry->region), // može biti null => globalno
            log: $log
        );

        $log['steps'][] = ['step' => 'alternatives_count', 'count' => $alternatives->count()];

        $log['reason'] = $alternatives->isNotEmpty()
            ? 'no_primary_used_alternatives'
            : 'no_availability';

        return [
            'primary'      => collect(),
            'alternatives' => $alternatives,
            'log'          => $log,
        ];
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
            ->with(['rooms'])
            ->limit(120);

        // location filter samo u primary
        if ($strictLocation) {
            $loc = $this->clean($inquiry->location);
            if ($loc) {
                $hotelsQ->where(function ($q) use ($loc) {
                    $q->where('hotel_map_city', 'like', "%{$loc}%")
                      ->orWhere('mesto', 'like', "%{$loc}%");
                });
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

        // ✅ mini stats za debug (da vidiš da li availability seče)
        $availabilityMiss = 0;

        foreach ($hotels as $hotel) {
            foreach ($hotel->rooms as $room) {
                if (! $this->roomFits($room, $adults, $children, $nights)) {
                    continue;
                }

                // ✅ availability check (Faza 1: po mesecima koje period zahvata)
                if (! $this->roomIsAvailableForRange((int) $room->room_id, $from, $to)) {
                    $availabilityMiss++;
                    continue;
                }

                $priceRow = $this->pickPriceRow((int) $room->room_id, $from, $to, $adults, $children);
                if (! $priceRow) {
                    continue;
                }

                $total = $this->calculateTotal($priceRow, $from, $to);
                if ($total <= 0) {
                    continue;
                }

                if ($inquiry->budget_max && $total > (int) $inquiry->budget_max) {
                    continue;
                }

                $perNight = $total / $nights;

                $results->push([
                    'hotel' => $hotel,
                    'room'  => $room,
                    'price' => [
                        'total'     => round($total, 2),
                        'per_night' => round($perNight, 2),
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
            'step' => 'availability_stats',
            'strictLocation' => $strictLocation,
            'availability_miss' => $availabilityMiss,
        ];

        return $results;
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

    /**
     * ✅ Availability: proveri da li postoji zapis u pt_rooms_availabilities
     * za svaki mesec koji traženi period zahvata.
     *
     * Faza 1: dovoljno je da za te mesece postoji availability zapis.
     */
    private function roomIsAvailableForRange(int $roomId, Carbon $from, Carbon $to): bool
    {
        // Availability tabela radi sa y=0 (current year) i y=1 (next year)
        $baseYear = now()->year;

        $cursor = $from->copy();

        while ($cursor->lt($to)) {
            $yearFlag = $cursor->year - $baseYear; // 0 ili 1
            if ($yearFlag < 0 || $yearFlag > 1) {
                return false;
            }

            $m = (int) $cursor->month;
            $day = (int) $cursor->day; // 1..31
            $col = 'd' . $day;

            $row = RoomAvailability::query()
                ->where('room_id', $roomId)
                ->where('y', $yearFlag)
                ->where('m', $m)
                ->first();

            if (! $row) {
                return false;
            }

            // d1..d31: ako je 0 -> nema raspoloživosti za taj dan
            $val = (int) ($row->{$col} ?? 0);
            if ($val <= 0) {
                return false;
            }

            $cursor->addDay();
        }

        return true;
    }

    private function pickPriceRow(int $roomId, Carbon $from, Carbon $to, int $adults, int $children): ?RoomPrice
    {
        $q = RoomPrice::query()
            ->where('room_id', $roomId)
            ->whereDate('date_from', '<=', $from->toDateString())
            ->whereDate('date_to', '>=', $to->copy()->subDay()->toDateString());

        $exact = (clone $q)
            ->where('adults', $adults)
            ->where('children', $children)
            ->orderByDesc('is_default')
            ->first();

        if ($exact) {
            return $exact;
        }

        return (clone $q)
            ->orderByDesc('is_default')
            ->first();
    }

    private function calculateTotal(RoomPrice $price, Carbon $from, Carbon $to): float
    {
        $total = 0.0;

        $cursor = $from->copy();
        while ($cursor->lt($to)) {
            $day = strtolower($cursor->format('D')); // mon,tue,wed...
            $value = (float) ($price->{$day} ?? 0);

            if ($value <= 0) {
                return 0.0;
            }

            $total += $value;
            $cursor->addDay();
        }

        return $total;
    }

    private function clean(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v !== '' ? $v : null;
    }

    /**
     * ✅ Alias da ne puca ViewInquiry kad zove fallbackAlternatives()
     * (da ne gomilamo nove metode - samo preusmerenje).
     */
    public function fallbackAlternatives(Inquiry $inquiry, int $limit = 5): Collection
    {
        return $this->findFallbackAlternatives($inquiry, $limit);
    }

    /**
     * ✅ Fallback bez hardkodovanja:
     * pokušava “relaxed” varijante i vraća candidates (hotel+room+price).
     *
     * Redosled:
     * 1) region bez mesta (ako postoji region)
     * 2) globalno (bez region/location)
     * 3) fleks datumi ±1..±3 dana (isti broj noćenja), globalno
     * 4) opusti budžet (globalno)
     */
    public function findFallbackAlternatives(Inquiry $inquiry, int $limit = 5): Collection
    {
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

        $log = [
            'input'  => ['fallback' => true],
            'reason' => null,
            'steps'  => [],
        ];

        // 1) Region bez mesta
        if ($this->clean($inquiry->region)) {
            $r1 = $this->runMatch(
                inquiry: $this->cloneInquiry($inquiry, ['location' => null]),
                from: $from,
                to: $to,
                nights: $nights,
                adults: $adults,
                children: $children,
                limit: $limit,
                strictLocation: false,
                regionOverride: $this->clean($inquiry->region),
                log: $log
            );

            if ($r1->isNotEmpty()) {
                return $r1->take($limit)->values();
            }
        }

        // 2) Globalno (bez region/location) – ista pravila za sobe/cene/availability
        $r2 = $this->runMatch(
            inquiry: $this->cloneInquiry($inquiry, ['region' => null, 'location' => null]),
            from: $from,
            to: $to,
            nights: $nights,
            adults: $adults,
            children: $children,
            limit: $limit,
            strictLocation: false,
            regionOverride: null,
            log: $log
        );

        if ($r2->isNotEmpty()) {
            return $r2->take($limit)->values();
        }

        // 3) Fleks datumi ±1..±3 (isti nights), globalno
        foreach ([1, 2, 3, -1, -2, -3] as $shift) {
            $f = $from->copy()->addDays($shift);
            $t = $f->copy()->addDays($nights);

            $r3 = $this->runMatch(
                inquiry: $this->cloneInquiry($inquiry, ['region' => null, 'location' => null]),
                from: $f,
                to: $t,
                nights: $nights,
                adults: $adults,
                children: $children,
                limit: $limit,
                strictLocation: false,
                regionOverride: null,
                log: $log
            );

            if ($r3->isNotEmpty()) {
                return $r3->take($limit)->values();
            }
        }

        // 4) Opusti budžet (globalno)
        $r4 = $this->runMatch(
            inquiry: $this->cloneInquiry($inquiry, [
                'region'     => null,
                'location'   => null,
                'budget_min' => null,
                'budget_max' => null,
            ]),
            from: $from,
            to: $to,
            nights: $nights,
            adults: $adults,
            children: $children,
            limit: $limit,
            strictLocation: false,
            regionOverride: null,
            log: $log
        );

        return $r4->take($limit)->values();
    }

    /**
     * Kloniramo inquiry samo u memoriji (ne diramo DB),
     * da možemo da testiramo različite “relaxed” kombinacije.
     */
    private function cloneInquiry(Inquiry $inquiry, array $patch): Inquiry
    {
        $x = $inquiry->replicate(); // ne čuva u bazu
        foreach ($patch as $k => $v) {
            $x->{$k} = $v;
        }

        return $x;
    }
}
