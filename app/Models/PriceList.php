<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    protected $fillable = [
        'original_filename',
        'original_path',
        'processed_path',
        'status',
        'source',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(PriceListRow::class);
    }
    public function logs(): HasMany
    {
        return $this->hasMany(PriceListLog::class);
    }
}
