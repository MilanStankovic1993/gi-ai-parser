<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\InquiryMissingData;

class Inquiry extends Model
{
    use HasFactory;

    // Statusi (drži jasno u jednom mestu)
    public const STATUS_NEW        = 'new';
    public const STATUS_IN_REVIEW  = 'in_review';
    public const STATUS_DONE       = 'done';
    public const STATUS_NO_AI      = 'no_ai';       // npr. limit reached ili ai_stopped
    public const STATUS_NEEDS_INFO = 'needs_info';  // missing data

    protected $fillable = [
        'source',
        'external_id',

        'guest_name',
        'guest_email',
        'guest_phone',

        'subject',
        'raw_message',
        'ai_draft',

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
        'extraction_debug' => 'array',

        'wants_near_beach' => 'boolean',
        'wants_parking'    => 'boolean',
        'wants_quiet'      => 'boolean',
        'wants_pets'       => 'boolean',
        'wants_pool'       => 'boolean',

        'is_priority' => 'boolean',
    ];

    protected $attributes = [
        'status' => self::STATUS_NEW,
        'is_priority' => false,
    ];

    public function aiInquiry()
    {
        return $this->hasOne(AiInquiry::class, 'inquiry_id');
    }

    /**
     * Normalizacija children_ages:
     * - null | "5,3" | "[5,3]" | ["5","3"] -> [5,3]
     */
    public function getChildrenAgesAttribute($value): array
    {
        // $value može biti: null | string | array (zbog $casts)
        return InquiryMissingData::normalizeChildrenAges($value);
    }

    public function setChildrenAgesAttribute($value): void
    {
        $ages = InquiryMissingData::normalizeChildrenAges($value);

        // ✅ u bazi čuvamo JSON string (da bude stabilno), a accessor vraća array
        $this->attributes['children_ages'] = json_encode($ages, JSON_UNESCAPED_UNICODE);
    }

    public function scopePriority($q)
    {
        return $q->where('is_priority', true);
    }

    public function scopeNew($q)
    {
        return $q->where('status', self::STATUS_NEW);
    }

    public function scopeNeedsInfo($q)
    {
        return $q->where('status', self::STATUS_NEEDS_INFO);
    }
}
