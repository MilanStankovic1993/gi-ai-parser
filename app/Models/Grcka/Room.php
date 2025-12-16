<?php

namespace App\Models\Grcka;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $connection = 'grcka';
    protected $table      = 'pt_rooms';
    protected $primaryKey = 'room_id';
    public    $timestamps = false;

    protected $casts = [
        'room_basic_price' => 'float',
        'room_adults'      => 'integer',
        'room_children'    => 'integer',
        'room_min_stay'    => 'integer',
        'room_quantity'    => 'integer',
    ];

    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'room_hotel', 'hotel_id');
    }

    public function prices()
    {
        return $this->hasMany(RoomPrice::class, 'room_id', 'room_id');
    }

    public function availabilities()
    {
        return $this->hasMany(RoomAvailability::class, 'room_id', 'room_id');
    }
}
