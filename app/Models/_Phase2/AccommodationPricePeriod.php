<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccommodationPricePeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'accommodation_id',
        'season_name',
        'date_from',
        'date_to',
        'price_per_night',
        'min_nights',
        'is_available',
        'note',
    ];

    protected $casts = [
        'date_from'        => 'date',
        'date_to'          => 'date',
        'price_per_night'  => 'integer',
        'min_nights'       => 'integer',
        'is_available'     => 'boolean',
    ];

    public function accommodation()
    {
        return $this->belongsTo(Accommodation::class);
    }
}
