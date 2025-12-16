<?php

namespace App\Models\Grcka;

use Illuminate\Database\Eloquent\Model;

class Hotel extends Model
{
    protected $connection = 'grcka';
    protected $table      = 'pt_hotels';
    protected $primaryKey = 'hotel_id';
    public    $timestamps = false;

    protected $casts = [
        'placen'    => 'integer',
        'valid2025' => 'integer',
        'ai_order'  => 'integer',
    ];

    // sobe
    public function rooms()
    {
        return $this->hasMany(Room::class, 'room_hotel', 'hotel_id')
            ->where('room_status', 'Yes');
    }

    // lokacija (grad)
    public function location()
    {
        return $this->belongsTo(Location::class, 'hotel_city', 'id');
    }

    /* Scopes koje ćemo koristiti u AI filter logici */

    // samo hoteli koje uzimamo u obzir u AI-ju
    public function scopeAiEligible($query)
    {
        return $query
            ->where('hotel_status', 'Yes')
            ->where('valid2025', 1);
    }

    // sortiranje po ai_order (null ide posle)
    public function scopeAiOrdered($query)
    {
        return $query->orderByRaw('ai_order IS NULL, ai_order ASC');
    }
}
