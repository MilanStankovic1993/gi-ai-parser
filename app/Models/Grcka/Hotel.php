<?php

namespace App\Models\Grcka;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Hotel extends Model
{
    protected $connection = 'grcka';
    protected $table      = 'pt_hotels';
    protected $primaryKey = 'hotel_id';
    public    $timestamps = false;

    protected $casts = [
        'placen'   => 'integer',
        'booking'  => 'integer',
        'cene2024' => 'integer',
        'ai_order' => 'integer',
        'hotel_udaljenost_plaza' => 'integer',
        'sortOrder' => 'integer',
    ];

    public function rooms()
    {
        return $this->hasMany(Room::class, 'room_hotel', 'hotel_id')
            ->where('room_status', 'Yes');
    }

    /**
     * Ako je u bazi hotel_city upisan kao ID (npr 270),
     * ovo omogućava da prikažemo naziv lokacije + region kao u njihovom adminu.
     */
    public function location()
    {
        return $this->belongsTo(Location::class, 'hotel_city', 'id');
    }

    /**
     * Faza 1 (1:1):
     * - provizijski: email booking@ / info@
     * - Booking=YES
     * - "Až. cene za 2024"=YES (cene2024=1)
     * - hotel_status=Yes
     */
    public function scopeAiEligible(Builder $query): Builder
    {
        return $query
            ->where('hotel_status', 'Yes')
            ->where(function (Builder $q) {
                $q->whereIn('hotel_email', ['booking@grckainfo.com', 'info@grckainfo.com'])
                  ->orWhereIn('custom_email', ['booking@grckainfo.com', 'info@grckainfo.com']);
            })
            ->where('booking', 1)
            ->where('cene2024', 1);
    }

    /**
     * 10 najveći prioritet (DESC). NULL na kraj.
     */
    public function scopeAiOrdered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('ai_order IS NULL')
            ->orderByDesc('ai_order')
            ->orderByDesc('sortOrder')
            ->orderBy('hotel_id');
    }

    /**
     * Minimalan “region/location” filter (string match).
     */
    public function scopeMatchRegion(Builder $query, ?string $region): Builder
    {
        $region = trim((string) $region);
        if ($region === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($region) {
            $q->where('mesto', 'like', "%{$region}%")
              ->orWhere('hotel_map_city', 'like', "%{$region}%")
              ->orWhere('hotel_city', 'like', "%{$region}%");
        });
    }

    public function getPublicUrlAttribute(): ?string
    {
        if (! $this->hotel_slug) {
            return null;
        }

        $base = rtrim(config('app.grcka_site_url', 'https://www.grckainfo.com'), '/');

        return "{$base}/smestaj/{$this->hotel_slug}";
    }

    public function getLocationLabelAttribute(): ?string
    {
        if ($this->relationLoaded('location') && $this->location) {
            $locName = trim((string) ($this->location->location ?? ''));
            $regName = trim((string) ($this->location->region->region ?? ''));
            $out = trim($locName . ($regName !== '' ? ', ' . $regName : ''));
            return $out !== '' ? $out : null;
        }

        // ako nije eager-load-ovano, probaj bez dodatnog query-ja:
        $fallback = trim((string) ($this->hotel_map_city ?: $this->mesto ?: ''));

        // ako je hotel_city tekst (nije broj), može i to:
        if ($fallback === '') {
            $city = trim((string) ($this->hotel_city ?? ''));
            if ($city !== '' && ! preg_match('/^\d+$/', $city)) {
                $fallback = $city;
            }
        }

        return $fallback !== '' ? $fallback : null;
    }
}
