<?php

namespace App\Models\Grcka;

use Illuminate\Database\Eloquent\Model;

class Inquiry extends Model
{
    protected $connection = 'grcka';
    protected $table = 'kontakt2';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $guarded = [];

    // Veza na hotel (objekat_id -> pt_hotels.hotel_id)
    public function hotel()
    {
        return $this->belongsTo(Hotel::class, 'room_hotel', 'hotel_id');
    }

    public function region()
    {
        return $this->belongsTo(Region::class, 'region_id', 'region_id');
    }
}
