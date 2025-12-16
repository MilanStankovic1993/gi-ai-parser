<?php

namespace App\Models\Grcka;

use Illuminate\Database\Eloquent\Model;

class RoomAvailability extends Model
{
    protected $connection = 'grcka';
    protected $table = 'pt_rooms_availabilities';
    protected $primaryKey = 'rva_id';
    public $timestamps = false;

    protected $guarded = [];

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id', 'room_id');
    }
}
