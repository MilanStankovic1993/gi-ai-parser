<?php

namespace App\Models\Grcka;

use Illuminate\Database\Eloquent\Model;

class RoomPrice extends Model
{
    protected $connection = 'grcka';
    protected $table = 'pt_rooms_prices';
    protected $primaryKey = 'rp_id';
    public $timestamps = false;

    protected $guarded = [];

    public function room()
    {
        return $this->belongsTo(Room::class, 'rp_room_id', 'room_id');
    }
}
