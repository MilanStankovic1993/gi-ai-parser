<?php

namespace App\Models\Grcka;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $connection = 'grcka';
    protected $table      = 'pt_locations';
    protected $primaryKey = 'id';
    public    $timestamps = false;

    public function region()
    {
        return $this->belongsTo(Region::class, 'region_id', 'region_id');
    }
}
