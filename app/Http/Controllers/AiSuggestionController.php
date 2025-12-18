<?php

namespace App\Http\Controllers;

use App\Models\Grcka\Hotel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AiSuggestionController extends Controller
{
    public function find(Request $request)
    {
        $data = $request->validate([
            'region'           => 'nullable|string',
            'location'         => 'nullable|string',
            'check_in'         => 'nullable|string', // trenutno ne koristimo u ovom controlleru
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
        if ($adults !== null) {
            $totalPersons += (int) $adults;
        }
        $totalPersons += (int) $childrenCount;

        $budgetPerNight = array_key_exists('budget_per_night', $data) ? $data['budget_per_night'] : null;

        // wants normalizacija: prihvati ili ["parking","wifi"] ili [" parking ", ...]
        $wantsRaw = $data['wants'] ?? [];
        $wants = array_values(array_unique(array_filter(array_map(function ($v) {
            if (! is_string($v)) return null;
            $v = trim($v);
            return $v !== '' ? $v : null;
        }, is_array($wantsRaw) ? $wantsRaw : []))));

        $wantMatchers = $this->wantMatchers();

        $query = Hotel::query()
            ->aiEligible()
            ->aiOrdered()
            ->with([
                'rooms',
                'location:id,location,region_id',
                // uzmi oba moguća polja, da ne puca
                'location.region:region_id,region,region_name',
            ]);

        // ==========================================================
        // 2) Lokacija / Region filter (SQL)
        // ==========================================================
        if (! empty($locationSearch)) {
            $query->whereHas('location', function ($q) use ($locationSearch) {
                $q->where('location', 'LIKE', '%' . $locationSearch . '%');
            });
        } elseif (! empty($regionSearch)) {
            // region filter: pokušaj region_name ili region
            $query->whereHas('location.region', function ($q) use ($regionSearch) {
                $q->where(function ($qq) use ($regionSearch) {
                    $qq->where('region_name', 'LIKE', '%' . $regionSearch . '%')
                       ->orWhere('region', 'LIKE', '%' . $regionSearch . '%');
                });
            });
        }

        // ==========================================================
        // 3) Room-level filter (SQL)
        // ==========================================================
        if ($budgetPerNight !== null || $totalPersons > 0 || $adults !== null) {
            $query->whereHas('rooms', function ($q) use ($budgetPerNight, $adults, $childrenCount, $totalPersons) {

                if ($budgetPerNight !== null) {
                    $q->whereNotNull('room_basic_price')
                      ->where('room_basic_price', '<=', $budgetPerNight);
                }

                if ($adults !== null || $childrenCount > 0) {
                    if ($adults !== null) {
                        $q->where('room_adults', '>=', (int) $adults);
                    }

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
            ->limit(80)
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
        // 5) Final filtering + wants + scoring
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

            $hotelRegionName =
                optional(optional($hotel->location)->region)->region_name
                ?? optional(optional($hotel->location)->region)->region
                ?? null;

            $label = $hotel->location_label; // accessor iz modela (fallback)

            $rooms = $hotel->rooms->filter(function ($room) use ($adults, $childrenCount, $totalPersons, $budgetPerNight) {
                // Kapacitet
                if ($adults !== null || $childrenCount > 0) {
                    $roomAdults   = (int) ($room->room_adults ?? 0);
                    $roomChildren = (int) ($room->room_children ?? 0);

                    if ($adults !== null && $roomAdults < (int) $adults) {
                        return false;
                    }

                    if ($totalPersons > 0 && ($roomAdults + $roomChildren) < (int) $totalPersons) {
                        return false;
                    }
                }

                // Budžet
                if ($budgetPerNight !== null) {
                    $price = $room->room_basic_price;
                    if ($price === null) return false;
                    if ((float) $price > (float) $budgetPerNight) return false;
                }

                return true;
            })->values();

            if ($rooms->isEmpty()) {
                return null;
            }

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

            // wants match
            $wantsMatched = [];
            foreach ($wants as $want) {
                $matcher = $wantMatchers[$want] ?? null;
                if (! $matcher) continue;

                $matched = $rooms->contains(function ($room) use ($matcher) {
                    $title = mb_strtolower((string) ($room->room_title ?? ''));
                    $amenLower = mb_strtolower((string) ($room->room_amenities ?? ''));

                    foreach (($matcher['keywords'] ?? []) as $kw) {
                        $kw = mb_strtolower((string) $kw);
                        if ($kw !== '' && Str::contains($title, $kw)) {
                            return true;
                        }
                    }

                    foreach (($matcher['amenity_ids'] ?? []) as $id) {
                        $id = mb_strtolower((string) $id);
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

            if ($hotel->hotel_basic_price !== null) {
                $score += 1;
            }

            return [
                'hotel_id'          => $hotel->hotel_id,
                'hotel_title'       => $hotel->hotel_title,
                'hotel_city'        => $hotel->hotel_city,
                'hotel_city_name'   => $hotelLocationName ?? $label,
                'hotel_region'      => $hotelRegionName,
                'hotel_location_label' => $label,
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
        //   BITNO: ai_order u tvom modelu je "10 najveći prioritet" => DESC
        // ==========================================================
        $sorted = $filtered->sort(function ($a, $b) {
            // ai_order DESC (null na kraj)
            $aoA = $a['ai_order'];
            $aoB = $b['ai_order'];

            $aNull = ($aoA === null);
            $bNull = ($aoB === null);

            if ($aNull !== $bNull) {
                return $aNull ? 1 : -1; // null ide na kraj
            }

            if (! $aNull && ! $bNull && (int) $aoA !== (int) $aoB) {
                return (int) $aoB <=> (int) $aoA; // DESC
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

            // najniža soba cena asc
            $minA = $this->minRoomPrice($a['rooms'] ?? []);
            $minB = $this->minRoomPrice($b['rooms'] ?? []);
            return $minA <=> $minB;
        })->values()->take(30)->values();

        return response()->json([
            'filters' => $data,
            'count'   => $sorted->count(),
            'results' => $sorted,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    protected function minRoomPrice(array $rooms): float
    {
        $prices = [];
        foreach ($rooms as $r) {
            $p = $r['room_basic_price'] ?? null;
            if ($p !== null) {
                $prices[] = (float) $p;
            }
        }

        return empty($prices) ? 9999999.0 : min($prices);
    }

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
