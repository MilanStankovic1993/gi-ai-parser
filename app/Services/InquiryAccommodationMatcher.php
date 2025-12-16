<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\Grcka\Hotel;
use App\Models\Grcka\Room;
use App\Models\Grcka\RoomPrice;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class InquiryAccommodationMatcher
{
    public function match(Inquiry $inquiry, int $limit = 5): Collection
    {
        // 1:1 – bez datuma ili osoba => nema ponude (kasnije “why_no_offer”)
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

        // Uzmi više hotela (po ai_order), pa filtriramo sobe/cene
        $hotels = Hotel::query()
            ->aiEligible()
            ->matchRegion($inquiry->region)
            ->aiOrdered()
            ->with(['rooms'])
            ->limit(80)
            ->get();

        $results = collect();

        foreach ($hotels as $hotel) {
            foreach ($hotel->rooms as $room) {
                if (! $this->roomFits($room, $adults, $children, $nights)) {
                    continue;
                }

                $priceRow = $this->pickPriceRow($room->room_id, $from, $to, $adults, $children);

                if (! $priceRow) {
                    continue;
                }

                $total = $this->calculateTotal($priceRow, $from, $to);
                if ($total <= 0) {
                    continue;
                }

                // budžet filter (ako postoji)
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
                ]);

                if ($results->count() >= $limit) {
                    break 2;
                }
            }
        }

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

    private function pickPriceRow(int $roomId, Carbon $from, Carbon $to, int $adults, int $children): ?RoomPrice
    {
        // tražimo cenu koja pokriva ceo period
        $q = RoomPrice::query()
            ->where('room_id', $roomId)
            ->whereDate('date_from', '<=', $from->toDateString())
            ->whereDate('date_to', '>=', $to->copy()->subDay()->toDateString());

        // prvo tačno match occupants, pa fallback default
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

            // fallback: ako je 0 u tabeli, nećemo računati kao validno
            if ($value <= 0) {
                return 0.0;
            }

            $total += $value;
            $cursor->addDay();
        }

        return $total;
    }
}
