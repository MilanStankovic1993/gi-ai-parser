<?php

namespace App\Models\Grcka;

use Illuminate\Database\Eloquent\Model;

class Region extends Model
{
    protected $connection = 'grcka';
    protected $table      = 'pt_regions';
    protected $primaryKey = 'region_id';
    public    $timestamps = false;

    protected $guarded = [];

    public function locations()
    {
        return $this->hasMany(Location::class, 'region_id', 'region_id');
    }
}
