<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;

class SuggestionService
{
    public function get(array $inq): array
    {
        $region  = $inq['region'] ?? null;
        $place   = $inq['location'] ?? null;
        $adults  = $inq['adults'] ?? 2;
        $children = $inq['children'] ?? [];
        $budget  = $inq['budget_per_night'] ?? null;

        $totalKids = count($children);

        $hotels = DB::table('hotels')
            ->where('valid2025', 1)
            ->where('placen', 0)
            ->when($region, fn($q) => $q->where('hotel_region', 'like', "%{$region}%"))
            ->when($place, fn($q) => $q->where('hotel_city_name', 'like', "%{$place}%"))
            ->orderBy('ai_order', 'asc')
            ->limit(200)
            ->get();

        $result = [];

        foreach ($hotels as $h) {
            $rooms = DB::table('rooms')
                ->where('room_hotel', $h->hotel_id)
                ->where('room_status', 'Yes')
                ->where('room_adults', '>=', $adults)
                ->where('room_children', '>=', $totalKids)
                ->when($budget, fn($q) => $q->where('room_basic_price', '<=', $budget + 20))
                ->orderBy('room_basic_price', 'asc')
                ->get()
                ->toArray();

            if (!empty($rooms)) {
                $h->rooms = $rooms;
                $result[] = $h;
            }
        }

        return $result;
    }
}
