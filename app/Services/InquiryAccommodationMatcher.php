<?php

namespace App\Services;

use App\Models\Accommodation;
use App\Models\Inquiry;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class InquiryAccommodationMatcher
{
    /**
     * Pronađi najbolje kandidate za zadati upit.
     *
     * @return \Illuminate\Support\Collection
     *
     * Svaki element kolekcije je array:
     * [
     *   'accommodation'   => Accommodation,
     *   'total_price'     => ?int,
     *   'price_per_night' => ?int,
     *   'matches'         => array<string, bool>, // npr. ['region' => true, 'parking' => false, ...]
     * ]
     */
    public function match(Inquiry $inquiry, int $limit = 5): Collection
    {
        $totalPeople = (int) ($inquiry->adults ?? 0) + (int) ($inquiry->children ?? 0);
        $region      = $inquiry->region;
        $dateFrom    = $inquiry->date_from ? Carbon::parse($inquiry->date_from) : null;
        $dateTo      = $inquiry->date_to ? Carbon::parse($inquiry->date_to) : null;
        $budgetMax   = $inquiry->budget_max;

        // 1) Osnovni filter: samo provizijski objekti, kapacitet i regija
        $query = Accommodation::query()
            ->where('is_commission', true)
            ->with('pricePeriods');

        if ($region) {
            $query->where(function ($q) use ($region) {
                $q->where('region', 'LIKE', '%' . $region . '%')
                  ->orWhere('settlement', 'LIKE', '%' . $region . '%');
            });
        }

        if ($totalPeople > 0) {
            $query->where('max_persons', '>=', $totalPeople);
        }

        $accommodations = $query->get();

        // 2) Izračunaj score i cenu za svakog kandidata
        $candidates = $accommodations
            ->map(function (Accommodation $acc) use ($inquiry, $dateFrom, $dateTo, $budgetMax, $totalPeople) {

                // Cena i min nights proveravamo preko cenovnih perioda
                $pricing = $this->calculatePriceForStay($acc, $dateFrom, $dateTo);

                // Ako imamo datume ali ne postoji odgovarajući cenovni period – preskoči
                if ($dateFrom && $dateTo && $pricing === null) {
                    return null;
                }

                $matches = [
                    'region'         => $this->matchRegion($inquiry, $acc),
                    'capacity'       => $this->matchCapacity($totalPeople, $acc),
                    'parking'        => $this->matchParking($inquiry, $acc),
                    'pets'           => $this->matchPets($inquiry, $acc),
                    'distance'       => $this->matchDistanceToBeach($inquiry, $acc),
                    'noise'          => $this->matchNoiseLevel($inquiry, $acc),
                    'budget'         => $this->matchBudget($budgetMax, $pricing['total_price'] ?? null),
                ];

                // Ukupan "score" – prosta suma pogodaka
                $score = collect($matches)->filter()->count();

                return [
                    'accommodation'   => $acc,
                    'total_price'     => $pricing['total_price'] ?? null,
                    'price_per_night' => $pricing['price_per_night'] ?? null,
                    'matches'         => $matches,
                    'score'           => $score,
                ];
            })
            ->filter() // izbaci null (one bez cene kad su traženi datumi)
            ->values();

        // 3) Sortiraj: prvo po score, pa po prioritetu, pa po ceni
        $sorted = $candidates->sortBy([
            ['score', 'desc'],
            ['accommodation.priority', 'desc'],
            ['total_price', 'asc'],
        ])->values();

        // 4) Ako postoji budžet, blago preferiraj one ispod budžeta
        if ($budgetMax) {
            $sorted = $sorted->sortBy(function ($item) use ($budgetMax) {
                $price = $item['total_price'] ?? $budgetMax * 10;

                return [
                    $price > $budgetMax ? 1 : 0, // prvo oni ispod budžeta
                    $price,                      // pa po ceni
                ];
            })->values();
        }

        return $sorted->take($limit);
    }

    /**
     * Računa cenu i minimalan broj noćenja.
     * Trenutna logika: pretpostavlja da ceo boravak pada u jedan cenovni period.
     */
    protected function calculatePriceForStay(Accommodation $acc, ?Carbon $from, ?Carbon $to): ?array
    {
        if (! $from || ! $to) {
            return null;
        }

        $nights = abs($from->diffInDays($to));
        if ($nights <= 0) {
            return null;
        }

        $period = $acc->pricePeriods
            ->first(function ($p) use ($from, $to, $nights) {
                // da li ceo range pada unutar perioda
                $pFrom = Carbon::parse($p->date_from);
                $pTo   = Carbon::parse($p->date_to);

                $insideRange = $from->greaterThanOrEqualTo($pFrom) && $to->lessThanOrEqualTo($pTo);

                if (! $insideRange) {
                    return false;
                }

                // poštuj minimalan broj noćenja
                if ($p->min_nights && $nights < $p->min_nights) {
                    return false;
                }

                return $p->is_available;
            });

        if (! $period) {
            return null;
        }

        $pricePerNight = (int) $period->price_per_night;
        $totalPrice    = $pricePerNight * $nights;

        return [
            'price_per_night' => $pricePerNight,
            'total_price'     => $totalPrice,
        ];
    }

    // ==== Helperi za pojedinačne "match" signale ====

    protected function matchRegion(Inquiry $inquiry, Accommodation $acc): bool
    {
        if (! $inquiry->region) {
            return false;
        }

        $region = mb_strtolower($inquiry->region);

        return str_contains(mb_strtolower($acc->region), $region)
            || str_contains(mb_strtolower((string) $acc->settlement), $region);
    }

    protected function matchCapacity(int $totalPeople, Accommodation $acc): bool
    {
        if ($totalPeople <= 0) {
            return false;
        }

        return $acc->max_persons >= $totalPeople;
    }

    protected function matchParking(Inquiry $inquiry, Accommodation $acc): bool
    {
        // Ako nemamo special_requirements u bazi, za sada samo vraćamo da li parking postoji
        return (bool) $acc->has_parking;
    }

    protected function matchPets(Inquiry $inquiry, Accommodation $acc): bool
    {
        return (bool) $acc->accepts_pets;
    }

    protected function matchDistanceToBeach(Inquiry $inquiry, Accommodation $acc): bool
    {
        // Za sada: ako je < 300m, smatramo "blizu plaže"
        if ($acc->distance_to_beach === null) {
            return false;
        }

        return $acc->distance_to_beach <= 300;
    }

    protected function matchNoiseLevel(Inquiry $inquiry, Accommodation $acc): bool
    {
        // Ako je objekat "quiet", tretiramo kao pogodak za one koji traže mir
        return $acc->noise_level === 'quiet';
    }

    protected function matchBudget(?int $budgetMax, ?int $totalPrice): bool
    {
        if (! $budgetMax || ! $totalPrice) {
            return false;
        }

        // Dozvolimo mali “buffer”, npr. +10%
        return $totalPrice <= ($budgetMax * 1.1);
    }
}
