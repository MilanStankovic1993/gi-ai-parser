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
        'ai_draft',

        // extracted summary
        'region',
        'location',
        'month_hint',
        'date_from',
        'date_to',
        'nights',
        'adults',
        'children',
        'children_ages',

        'budget_min',
        'budget_max',

        'wants_near_beach',
        'wants_parking',
        'wants_quiet',
        'wants_pets',
        'wants_pool',
        'special_requirements',

        // extraction meta (prod korisno)
        'extraction_mode',
        'extraction_debug',
        'language',

        'status',
        'reply_mode',
        'is_priority',

        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'date_from'    => 'date',
        'date_to'      => 'date',

        'received_at'  => 'datetime',
        'processed_at' => 'datetime',

        'nights'       => 'integer',
        'adults'       => 'integer',
        'children'     => 'integer',
        'budget_min'   => 'integer',
        'budget_max'   => 'integer',

        'children_ages' => 'array',

        'wants_near_beach' => 'boolean',
        'wants_parking'    => 'boolean',
        'wants_quiet'      => 'boolean',
        'wants_pets'       => 'boolean',
        'wants_pool'       => 'boolean',

        'is_priority' => 'boolean',
    ];
    public function aiInquiry()
    {
        return $this->hasOne(\App\Models\AiInquiry::class, 'inquiry_id');
    }

}
