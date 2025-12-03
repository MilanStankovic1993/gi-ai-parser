<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'external_id',
        'guest_name',
        'guest_email',
        'guest_phone',
        'subject',
        'raw_message',
        'region',
        'date_from',
        'date_to',
        'nights',
        'adults',
        'children',
        'budget_min',
        'budget_max',
        'status',
        'reply_mode',
        'is_priority',
        'received_at',
        'processed_at',
        'wants_near_beach',
        'wants_parking',
        'wants_quiet',
        'wants_pets',
        'wants_pool',
    ];

    protected $casts = [
        'date_from' => 'datetime',
        'date_to'   => 'datetime',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',

        'wants_near_beach' => 'boolean',
        'wants_parking'    => 'boolean',
        'wants_quiet'      => 'boolean',
        'wants_pets'       => 'boolean',
        'wants_pool'       => 'boolean',
    ];
}
