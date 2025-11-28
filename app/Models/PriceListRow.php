<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListRow extends Model
{
    protected $fillable = [
        'price_list_id',
        'sezona_od',
        'sezona_do',
        'tip_jedinice',
        'cena_noc',
        'min_noci',
        'doplate',
        'promo',
        'napomena',
    ];

    protected $casts = [
        'sezona_od' => 'date',
        'sezona_do' => 'date',
        'cena_noc'  => 'decimal:2',
    ];

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
