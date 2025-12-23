<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Inquiry extends Model
{
    protected $table = 'inquiries';

    public const STATUS_NEW        = 'new';
    public const STATUS_NEEDS_INFO = 'needs_info';
    public const STATUS_EXTRACTED  = 'extracted';
    public const STATUS_SUGGESTED  = 'suggested';
    public const STATUS_REPLIED    = 'replied';
    public const STATUS_CLOSED     = 'closed';
    public const STATUS_NO_AI      = 'no_ai';

    public const REPLY_MODE_AI     = 'ai_draft';
    public const REPLY_MODE_MANUAL = 'manual';

    protected $fillable = [
        'source',
        'external_id',

        'guest_name',
        'guest_email',
        'guest_phone',

        'subject',
        'raw_message',
        'ai_draft',

        // canonical contract
        'intent',
        'entities',
        'travel_time',
        'party',
        'location_json',
        'units',
        'wishes',
        'questions',
        'tags',
        'why_no_offer',

        // summary
        'region',
        'location',
        'date_from',
        'date_to',
        'month_hint',
        'nights',
        'adults',
        'children',
        'children_ages',
        'budget_min',
        'budget_max',

        // wants_* (tri-state)
        'wants_near_beach',
        'wants_parking',
        'wants_quiet',
        'wants_pets',
        'wants_pool',
        'special_requirements',

        'language',

        'status',
        'reply_mode',

        'extraction_mode',
        'extraction_debug',

        'is_priority',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        // canonical contract
        'intent'           => 'string',
        'entities'         => 'array',
        'travel_time'      => 'array',
        'party'            => 'array',
        'location_json'    => 'array',
        'units'            => 'array',
        'wishes'           => 'array',
        'questions'        => 'array',
        'tags'             => 'array',
        'why_no_offer'     => 'array',

        // meta/debug
        'extraction_debug' => 'array',

        // summary
        'children_ages'    => 'array',
        'date_from'        => 'date',
        'date_to'          => 'date',

        // flags: NE castujemo u boolean da null ostane null

        'language'         => 'string',
        'is_priority'      => 'boolean',
        'received_at'      => 'datetime',
        'processed_at'     => 'datetime',
    ];

    /**
     * Stabilni default-i (da UI/servisi mogu bezbedno da rade sa ?? [] bez JSON string defaulta u DB).
     */
    protected $attributes = [
        'intent'           => null,
        'entities'         => null,
        'travel_time'      => null,
        'party'            => null,
        'location_json'    => null,
        'units'            => null,
        'wishes'           => null,
        'questions'        => null,
        'tags'             => null,
        'why_no_offer'     => null,
        'extraction_debug' => null,
    ];

    public function aiInquiry(): HasOne
    {
        return $this->hasOne(AiInquiry::class, 'inquiry_id', 'id');
    }
}
