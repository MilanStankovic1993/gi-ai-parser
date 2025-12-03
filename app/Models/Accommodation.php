<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Accommodation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'region',
        'settlement',
        'unit_type',
        'bedrooms',
        'max_persons',
        'distance_to_beach',
        'beach_type',
        'has_parking',
        'accepts_pets',
        'noise_level',
        'availability_note',
        'internal_contact',
        'is_commission',
        'has_pool',
        'priority',
    ];

    protected $casts = [
        'bedrooms'          => 'integer',
        'max_persons'       => 'integer',
        'distance_to_beach' => 'integer',
        'has_parking'       => 'boolean',
        'accepts_pets'      => 'boolean',
        'is_commission'     => 'boolean',
        'has_pool'          => 'boolean',
        'priority'          => 'integer',
    ];
    public function pricePeriods()
    {
        return $this->hasMany(\App\Models\AccommodationPricePeriod::class);
    }

}
