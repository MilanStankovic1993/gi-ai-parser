<?php

namespace App\Http\Controllers;

use App\Models\Grcka\Hotel;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AiSuggestionController extends Controller
{
    public function find(Request $request)
    {
        // 1) Ovo odgovara strukturi iz parsed inquirya
        $data = $request->validate([
            'region'           => 'nullable|string',
            'location'         => 'nullable|string',
            'check_in'         => 'nullable|string', // kasnije može biti date
            'nights'           => 'nullable|integer',
            'adults'           => 'nullable|integer|min:0',
            'children'         => 'nullable|array',  // npr: [ ["age" => 5], ... ]
            'budget_per_night' => 'nullable|numeric|min:0',
            'wants'            => 'nullable|array',
        ]);

        $locationSearch = isset($data['location']) ? trim((string) $data['location']) : null;
        $regionSearch   = isset($data['region']) ? trim((string) $data['region']) : null;

        $adults = array_key_exists('adults', $data) ? $data['adults'] : null;

        $childrenArray = $data['children'] ?? [];
        $childrenCount = is_array($childrenArray) ? count($childrenArray) : 0;

        $totalPersons = 0;
        if (! is_null($adults)) {
            $totalPersons += (int) $adults;
        }
        $totalPersons += (int) $childrenCount;

        $budgetPerNight = array_key_exists('budget_per_night', $data) ? $data['budget_per_night'] : null;

        $wants = array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : null,
            $data['wants'] ?? []
        )));

        // map wants flags na "keywords" i/ili amenity ids (ako koristiš kodove)
        // Ovo je heuristika - proširi po tvojoj bazi.
        $wantMatchers = $this->wantMatchers();

        $query = Hotel::query()
            ->aiEligible()
            ->aiOrdered()
            ->with([
                'rooms',
                'location:id,location,region_id',
                'location.region:region_id,region_name',
            ]);

        // ==========================================================
        // 2) Lokacija / Region filter (SQL)
        // ==========================================================
        if (! empty($locationSearch)) {
            $query->whereHas('location', function ($q) use ($locationSearch) {
                $q->where('location', 'LIKE', '%' . $locationSearch . '%');
            });
        } elseif (! empty($regionSearch)) {
            $query->whereHas('location.region', function ($q) use ($regionSearch) {
                $q->where('region_name', 'LIKE', '%' . $regionSearch . '%');
            });
        }

        // ==========================================================
        // 3) Room-level filter (SQL) – da ne vučeš previše
        //    (Budžet + kapacitet) ako je moguće.
        // ==========================================================
        if (! is_null($budgetPerNight) || $totalPersons > 0 || ! is_null($adults)) {
            $query->whereHas('rooms', function ($q) use ($budgetPerNight, $adults, $childrenCount, $totalPersons) {

                if (! is_null($budgetPerNight)) {
                    $q->whereNotNull('room_basic_price')
                      ->where('room_basic_price', '<=', $budgetPerNight);
                }

                // kapacitet – samo ako imamo neke osobe
                if (! is_null($adults) || $childrenCount > 0) {

                    // minimalno: room_adults >= adults (ako adults poznat)
                    if (! is_null($adults)) {
                        $q->where('room_adults', '>=', (int) $adults);
                    }

                    // ukupno mesta: room_adults + room_children >= totalPersons
                    // (SQL raw, jer je zbir kolona)
                    if ($totalPersons > 0) {
                        $q->whereRaw('(COALESCE(room_adults,0) + COALESCE(room_children,0)) >= ?', [(int) $totalPersons]);
                    }
                }
            });
        }

        // ==========================================================
        // 4) Dohvati
        // ==========================================================
        $hotels = $query
            ->limit(80) // malo veći limit, pa posle sečemo + score/sort
            ->get([
                'hotel_id',
                'hotel_title',
                'hotel_city',
                'hotel_basic_price',
                'placen',
                'valid2025',
                'ai_order',
            ]);

        // ==========================================================
        // 5) Finalno filtriranje soba + wants matching + scoring
        // ==========================================================
        $filtered = $hotels->map(function (Hotel $hotel) use (
            $locationSearch,
            $regionSearch,
            $adults,
            $childrenCount,
            $totalPersons,
            $budgetPerNight,
            $wants,
            $wantMatchers
        ) {
            $hotelLocationName = optional($hotel->location)->location;
            $hotelRegionName   = optional(optional($hotel->location)->region)->region_name;

            // ----- room filtering (PHP final) -----
            $rooms = $hotel->rooms->filter(function ($room) use ($adults, $childrenCount, $totalPersons, $budgetPerNight) {

                // Kapacitet
                if (! is_null($adults) || $childrenCount > 0) {
                    $roomAdults   = (int) ($room->room_adults ?? 0);
                    $roomChildren = (int) ($room->room_children ?? 0);

                    if (! is_null($adults) && $roomAdults < (int) $adults) {
                        return false;
                    }

                    if ($totalPersons > 0 && ($roomAdults + $roomChildren) < (int) $totalPersons) {
                        return false;
                    }
                }

                // Budžet
                if (! is_null($budgetPerNight)) {
                    $price = $room->room_basic_price;
                    if (is_null($price)) {
                        return false;
                    }
                    if ((float) $price > (float) $budgetPerNight) {
                        return false;
                    }
                }

                return true;
            })->values();

            if ($rooms->isEmpty()) {
                return null;
            }

            // ----- scoring -----
            $score = 0;

            // location match boost
            if (! empty($locationSearch) && ! empty($hotelLocationName)) {
                if (Str::contains(mb_strtolower($hotelLocationName), mb_strtolower($locationSearch))) {
                    $score += 30;
                }
            }

            // region match boost
            if (empty($locationSearch) && ! empty($regionSearch) && ! empty($hotelRegionName)) {
                if (Str::contains(mb_strtolower($hotelRegionName), mb_strtolower($regionSearch))) {
                    $score += 15;
                }
            }

            // wants match: proveravamo po sobama (amenities + title)
            $wantsMatched = [];
            foreach ($wants as $want) {
                $matcher = $wantMatchers[$want] ?? null;
                if (! $matcher) {
                    continue;
                }

                $matched = $rooms->contains(function ($room) use ($matcher) {
                    $title = mb_strtolower((string) ($room->room_title ?? ''));
                    $amen = (string) ($room->room_amenities ?? ''); // može biti CSV ili null
                    $amenLower = mb_strtolower($amen);

                    // keyword match
                    foreach (($matcher['keywords'] ?? []) as $kw) {
                        if ($kw !== '' && Str::contains($title, $kw)) {
                            return true;
                        }
                    }

                    // amenity code match (ako koristiš kodove u CSV stringu)
                    foreach (($matcher['amenity_ids'] ?? []) as $id) {
                        $id = (string) $id;
                        if ($id !== '' && Str::contains($amenLower, $id)) {
                            return true;
                        }
                    }

                    return false;
                });

                if ($matched) {
                    $wantsMatched[] = $want;
                }
            }

            $score += count($wantsMatched) * 7;

            // cene: preferiraj niže (blagi boost ako ima basic_price)
            if (! is_null($hotel->hotel_basic_price)) {
                $score += 1;
            }

            // vrati hotel + rooms
            return [
                'hotel_id'          => $hotel->hotel_id,
                'hotel_title'       => $hotel->hotel_title,
                'hotel_city'        => $hotel->hotel_city,
                'hotel_city_name'   => $hotelLocationName,
                'hotel_region'      => $hotelRegionName,
                'hotel_basic_price' => $hotel->hotel_basic_price,
                'placen'            => $hotel->placen,
                'valid2025'         => $hotel->valid2025,
                'ai_order'          => $hotel->ai_order,
                'score'             => $score,
                'wants_matched'     => array_values(array_unique($wantsMatched)),
                'rooms'             => $rooms->map(function ($room) {
                    return [
                        'room_id'            => $room->room_id,
                        'room_title'         => $room->room_title,
                        'room_basic_price'   => $room->room_basic_price,
                        'room_adults'        => $room->room_adults,
                        'room_children'      => $room->room_children,
                        'room_min_stay'      => $room->room_min_stay,
                        'room_type'          => $room->room_type,
                        'room_amenities_raw' => $room->room_amenities,
                        'room_status'        => $room->room_status,
                    ];
                })->values(),
            ];
        })
        ->filter()
        ->values();

        // ==========================================================
        // 6) Sort + limit
        //    - ai_order asc (tvoj prioritet)
        //    - placen desc (plaćeni prvo)
        //    - score desc (wants / match)
        //    - najniža cena sobe asc (ako hoćeš)
        // ==========================================================
        $sorted = $filtered->sort(function ($a, $b) {

            // ai_order (null na kraj)
            $aoA = $a['ai_order'] ?? PHP_INT_MAX;
            $aoB = $b['ai_order'] ?? PHP_INT_MAX;
            if ($aoA !== $aoB) {
                return $aoA <=> $aoB;
            }

            // placen desc
            $pA = (int) ($a['placen'] ?? 0);
            $pB = (int) ($b['placen'] ?? 0);
            if ($pA !== $pB) {
                return $pB <=> $pA;
            }

            // score desc
            $sA = (int) ($a['score'] ?? 0);
            $sB = (int) ($b['score'] ?? 0);
            if ($sA !== $sB) {
                return $sB <=> $sA;
            }

            // najniža soba cena asc (fallback)
            $minA = $this->minRoomPrice($a['rooms'] ?? []);
            $minB = $this->minRoomPrice($b['rooms'] ?? []);
            return $minA <=> $minB;
        })->values()->take(30)->values();

        return response()->json([
            'filters' => $data,
            'count'   => $sorted->count(),
            'results' => $sorted,
        ]);
    }

    protected function minRoomPrice(array $rooms): float
    {
        $prices = [];
        foreach ($rooms as $r) {
            $p = $r['room_basic_price'] ?? null;
            if (! is_null($p)) {
                $prices[] = (float) $p;
            }
        }
        return empty($prices) ? 9999999 : min($prices);
    }

    /**
     * wants -> heuristički match (keywords u room_title + (opciono) amenity ids u room_amenities CSV)
     * Proširi po tvojoj šemi.
     */
    protected function wantMatchers(): array
    {
        return [
            'close_to_beach' => [
                'keywords' => ['beach', 'plaža', 'plaza', 'seaside', 'sea view', 'na pla'],
                'amenity_ids' => [],
            ],
            'parking' => [
                'keywords' => ['parking', 'garage', 'gara'],
                'amenity_ids' => [],
            ],
            'quiet_location' => [
                'keywords' => ['quiet', 'mirno', 'tiho'],
                'amenity_ids' => [],
            ],
            'noise_sensitive' => [
                'keywords' => ['quiet', 'mirno', 'tiho'],
                'amenity_ids' => [],
            ],
            'pets_allowed' => [
                'keywords' => ['pet', 'pets', 'ljubim', 'pas'],
                'amenity_ids' => [],
            ],
            'pool' => [
                'keywords' => ['pool', 'bazen'],
                'amenity_ids' => [],
            ],
            'wifi' => [
                'keywords' => ['wifi', 'wi-fi'],
                'amenity_ids' => [],
            ],
            'ac' => [
                'keywords' => ['a/c', 'ac', 'air', 'klima'],
                'amenity_ids' => [],
            ],
        ];
    }
}
