<?php

namespace App\Models\Grcka;

use Illuminate\Database\Eloquent\Model;

class RoomPrice extends Model
{
    protected $connection = 'grcka';
    protected $table      = 'pt_rooms_prices';
    protected $primaryKey = 'id';
    public    $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'date_from' => 'date',
        'date_to'   => 'date',
        'adults'    => 'integer',
        'children'  => 'integer',
        'is_default'=> 'integer',

        'mon' => 'float', 'tue' => 'float', 'wed' => 'float', 'thu' => 'float',
        'fri' => 'float', 'sat' => 'float', 'sun' => 'float',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id', 'room_id');
    }
}
