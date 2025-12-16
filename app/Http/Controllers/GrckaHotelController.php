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
                'location.region:region_id,region_name',
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

        $data = $hotels->map(function ($hotel) {
            return [
                'hotel_id'         => $hotel->hotel_id,
                'hotel_title'      => $hotel->hotel_title,
                'hotel_city'       => $hotel->hotel_city, // id
                'hotel_city_name'  => optional($hotel->location)->location,
                'hotel_region'     => optional(optional($hotel->location)->region)->region_name,
                'hotel_basic_price'=> $hotel->hotel_basic_price,
                'placen'           => $hotel->placen,
                'valid2025'        => $hotel->valid2025,
                'ai_order'         => $hotel->ai_order,
                'rooms'            => $hotel->rooms->map(function ($room) {
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

        return response()->json($data);
    }
}
