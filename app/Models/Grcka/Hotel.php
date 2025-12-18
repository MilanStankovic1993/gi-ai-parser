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
        'booking'  => 'string',
        'cene2024' => 'string',
        'ai_order' => 'integer',
        'hotel_udaljenost_plaza' => 'integer',
        'sortOrder' => 'integer',
    ];

    public function rooms()
    {
        return $this->hasMany(Room::class, 'room_hotel', 'hotel_id')
            ->where('room_status', 'Yes');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'hotel_city', 'id');
    }

    public function scopeAiEligible(Builder $query): Builder
    {
        return $query
            ->where('hotel_status', 'Yes')
            ->where(function (Builder $q) {
                $q->whereIn('hotel_email', ['booking@grckainfo.com', 'info@grckainfo.com'])
                  ->orWhereIn('custom_email', ['booking@grckainfo.com', 'info@grckainfo.com']);
            })
            ->whereIn('booking', ['1', 'Yes', 'YES', 1])
            ->whereIn('cene2024', ['1', 'Yes', 'YES', 1]);
    }

    public function scopeAiOrdered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('ai_order IS NULL')
            ->orderByDesc('ai_order')
            ->orderByDesc('sortOrder')
            ->orderBy('hotel_id');
    }

    public function scopeMatchRegion(Builder $query, ?string $region): Builder
    {
        $region = trim((string) $region);
        if ($region === '') return $query;

        return $query->where(function (Builder $q) use ($region) {
            $q->where('mesto', 'like', "%{$region}%")
              ->orWhere('hotel_map_city', 'like', "%{$region}%")
              ->orWhere('hotel_city', 'like', "%{$region}%");
        });
    }

    public function getPublicUrlAttribute(): ?string
    {
        if (! $this->hotel_slug) return null;

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

        $fallback = trim((string) ($this->hotel_map_city ?: $this->mesto ?: ''));
        if ($fallback === '') {
            $city = trim((string) ($this->hotel_city ?? ''));
            if ($city !== '' && ! preg_match('/^\d+$/', $city)) {
                $fallback = $city;
            }
        }

        return $fallback !== '' ? $fallback : null;
    }
}
