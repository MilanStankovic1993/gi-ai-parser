<?php

namespace App\Services\Ai;

use App\Models\Grcka\Hotel;
use App\Models\Grcka\Location;
use App\Models\Grcka\Room;
use Illuminate\Support\Str;

class SuggestionService
{
    /**
     * Staro ponašanje (ako ga negde koristiš).
     * Vrati samo listu hotela sa sobama.
     */
    public function get(array $inq): array
    {
        $out = $this->getWithAlternatives($inq);

        // ako ima primary, vrati primary, inače alternatives
        $primary = $out['primary'] ?? [];
        if (! empty($primary)) {
            return $primary;
        }

        return $out['alternatives'] ?? [];
    }

    /**
     * Novo ponašanje: vrati primary + alternatives + log.
     */
    public function getWithAlternatives(array $inq): array
    {
        $region   = trim((string) ($inq['region'] ?? ''));
        $place    = trim((string) ($inq['location'] ?? ''));
        $adults   = (int) ($inq['adults'] ?? 2);
        $children = is_array($inq['children'] ?? null) ? $inq['children'] : [];
        $budget   = $inq['budget_per_night'] ?? null;

        $kids = count($children);

        $log = [
            'input' => [
                'region' => $region ?: null,
                'place' => $place ?: null,
                'adults' => $adults,
                'kids' => $kids,
                'budget_per_night' => is_numeric($budget) ? (float) $budget : null,
            ],
            'steps' => [],
            'reason' => null,
        ];

        // 1) map place -> pt_locations.id (ako možemo)
        $locationId = null;
        $locationRow = null;

        if ($place !== '') {
            $locationRow = Location::query()
                ->where(function ($q) use ($place) {
                    $q->where('location', 'like', "%{$place}%")
                      ->orWhere('title', 'like', "%{$place}%")
                      ->orWhere('h1', 'like', "%{$place}%");
                })
                ->first(['id', 'region_id', 'region', 'location', 'title', 'h1']);

            $locationId = $locationRow?->id;
        }

        $log['steps'][] = [
            'step' => 'place_to_location_id',
            'place' => $place ?: null,
            'location_id' => $locationId,
            'location_row' => $locationRow ? $locationRow->toArray() : null,
        ];

        // PRIMARY (strogo)
        $primaryHotels = $this->findHotels(
            region: $region ?: null,
            place: $place,
            locationId: $locationId,
            adults: $adults,
            kids: $kids,
            budget: $budget,
            strictEligible: true,
            limitHotels: 250,
            capResults: 80,
            logLabel: 'primary_strict'
        );

        $log['steps'][] = [
            'step' => 'primary_strict_result',
            'count' => count($primaryHotels),
        ];

        if (! empty($primaryHotels)) {
            $log['reason'] = 'found_primary';
            return [
                'primary' => $primaryHotels,
                'alternatives' => [],
                'log' => $log,
            ];
        }

        // ALTERNATIVES (po standardima, ali šire pretraga)
        // Pravilo: i dalje aiEligible + aiOrdered, samo popuštamo "place" filter:
        // - ako imamo region -> probamo samo region
        // - ako imamo locationRow->region -> probamo taj region string
        // - ako ništa -> probamo samo aiEligible globalno (ali uz limit)
        $altRegion = $region;

        if ($altRegion === '' && $locationRow && !empty($locationRow->region)) {
            $altRegion = (string) $locationRow->region;
        }

        $log['steps'][] = [
            'step' => 'alternatives_region_choice',
            'alt_region' => $altRegion ?: null,
        ];

        $altHotels = $this->findHotels(
            region: $altRegion ?: null,
            place: '',              // <<< namerno prazno (ne vezujemo se za Pefkohori)
            locationId: null,       // <<< namerno null
            adults: $adults,
            kids: $kids,
            budget: $budget,
            strictEligible: true,   // <<< i dalje strogo po proviziji + booking + cene2024
            limitHotels: 300,
            capResults: 80,
            logLabel: 'alternatives_region'
        );

        $log['steps'][] = [
            'step' => 'alternatives_result',
            'count' => count($altHotels),
        ];

        if (! empty($altHotels)) {
            $log['reason'] = 'no_primary_found_used_alternatives';
            return [
                'primary' => [],
                'alternatives' => $altHotels,
                'log' => $log,
            ];
        }

        // Nema ni alternative po standardima
        $log['reason'] = 'no_availability_even_alternatives';
        return [
            'primary' => [],
            'alternatives' => [],
            'log' => $log,
        ];
    }

    /**
     * Interna funkcija: izvuče hotele + sobe po filterima.
     */
    private function findHotels(
        ?string $region,
        string $place,
        ?int $locationId,
        int $adults,
        int $kids,
        mixed $budget,
        bool $strictEligible,
        int $limitHotels,
        int $capResults,
        string $logLabel
    ): array {
        $hotelsQ = Hotel::query()
            ->when($strictEligible, fn($q) => $q->aiEligible())
            ->aiOrdered()
            ->when($region, fn($q) => $q->matchRegion($region))
            ->when($locationId, fn($q) => $q->where('hotel_city', $locationId))
            ->when(!$locationId && trim($place) !== '', function ($q) use ($place) {
                $p = trim($place);
                $q->where(function ($qq) use ($p) {
                    $qq->where('hotel_map_city', 'like', "%{$p}%")
                       ->orWhere('mesto', 'like', "%{$p}%")
                       ->orWhere('hotel_city', 'like', "%{$p}%");
                });
            })
            ->limit($limitHotels);

        $hotels = $hotelsQ->get();

        if ($hotels->isEmpty()) {
            return [];
        }

        $result = [];

        foreach ($hotels as $hotel) {
            $roomsQ = Room::query()
                ->where('room_hotel', $hotel->hotel_id)
                ->where('room_status', 'Yes')
                ->where('room_adults', '>=', $adults);

            // kids: faza 1 = računamo samo count (godine obrađujemo u parse delu)
            if ($kids > 0) {
                $roomsQ->where('room_children', '>=', $kids);
            }

            if (is_numeric($budget)) {
                $roomsQ->where('room_basic_price', '<=', ((float) $budget) + 20);
            }

            $rooms = $roomsQ
                ->orderBy('room_basic_price', 'asc')
                ->limit(30)
                ->get()
                ->toArray();

            if (! empty($rooms)) {
                // ubaci rooms (kao array) da payload bude stabilan
                $hotelArr = $hotel->toArray();
                $hotelArr['_match'] = $logLabel;
                $hotelArr['rooms'] = $rooms;
                $result[] = $hotelArr;
            }

            if (count($result) >= $capResults) {
                break;
            }
        }

        return $result;
    }
}
