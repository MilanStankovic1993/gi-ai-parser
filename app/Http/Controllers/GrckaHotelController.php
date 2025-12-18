<?php

namespace App\Http\Controllers;

use App\Models\Grcka\Hotel;

class GrckaHotelController extends Controller
{
    public function index()
    {
        $hotels = Hotel::query()
            ->aiEligible()
            ->aiOrdered()
            ->with([
                'rooms',
                'location:id,location,region_id',
                // uzmi oba moguća naziva kolone za region
                'location.region:region_id,region,region_name',
            ])
            ->get([
                'hotel_id',
                'hotel_title',
                'hotel_city',
                'hotel_basic_price',
                'placen',
                'valid2025',
                'ai_order',
            ]);

        $data = $hotels->map(function (Hotel $hotel) {
            $hotelLocationName = optional($hotel->location)->location;

            $hotelRegionName =
                optional(optional($hotel->location)->region)->region_name
                ?? optional(optional($hotel->location)->region)->region
                ?? null;

            // fallback: koristi accessor koji si već napravio (location + region u jednom)
            $label = $hotel->location_label; // getLocationLabelAttribute()

            return [
                'hotel_id'          => $hotel->hotel_id,
                'hotel_title'       => $hotel->hotel_title,
                'hotel_city'        => $hotel->hotel_city, // id ili string, zavisi od baze
                'hotel_city_name'   => $hotelLocationName ?? $label,
                'hotel_region'      => $hotelRegionName,
                'hotel_location_label' => $label,
                'hotel_basic_price' => $hotel->hotel_basic_price,
                'placen'            => $hotel->placen,
                'valid2025'         => $hotel->valid2025,
                'ai_order'          => $hotel->ai_order,
                'rooms'             => $hotel->rooms->map(function ($room) {
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
        })->values();

        return response()->json($data, 200, [], JSON_UNESCAPED_UNICODE);
    }
}
