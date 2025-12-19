<?php

namespace App\Models;

use App\Services\InquiryMissingData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inquiry extends Model
{
    use HasFactory;

    public const STATUS_NEW        = 'new';
    public const STATUS_NEEDS_INFO = 'needs_info';
    public const STATUS_EXTRACTED  = 'extracted';
    public const STATUS_SUGGESTED  = 'suggested';
    public const STATUS_REPLIED    = 'replied';
    public const STATUS_CLOSED     = 'closed';
    public const STATUS_NO_AI      = 'no_ai';

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

        'nights'     => 'integer',
        'adults'     => 'integer',
        'children'   => 'integer',
        'budget_min' => 'integer',
        'budget_max' => 'integer',

        // DB kolone su json
        'children_ages'    => 'array',
        'extraction_debug' => 'array',

        'wants_near_beach' => 'boolean',
        'wants_parking'    => 'boolean',
        'wants_quiet'      => 'boolean',
        'wants_pets'       => 'boolean',
        'wants_pool'       => 'boolean',

        'is_priority' => 'boolean',
    ];

    protected $attributes = [
        'status'      => self::STATUS_NEW,
        'reply_mode'  => 'ai_draft',
        'is_priority' => false,
    ];

    public function aiInquiry()
    {
        return $this->hasOne(AiInquiry::class, 'inquiry_id');
    }

    /**
     * children_ages normalizacija:
     * - null | "5,3" | "[5,3]" | ["5","3"] -> [5,3]
     */
    public function getChildrenAgesAttribute($value): array
    {
        return InquiryMissingData::normalizeChildrenAges($value);
    }

    public function setChildrenAgesAttribute($value): void
    {
        $normalized = InquiryMissingData::normalizeChildrenAges($value);

        // Pošto je kolona json, najstabilnije je upisati JSON string.
        // (Eloquent cast će svakako raditi, ali ovako izbegavamo edge slučajeve)
        $this->attributes['children_ages'] = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function setExtractionDebugAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['extraction_debug'] = json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $payload = is_array($decoded) ? $decoded : ['raw' => $value];

            $this->attributes['extraction_debug'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $payload = is_array($value) ? $value : (array) $value;

        $this->attributes['extraction_debug'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // Scopes
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

    public function scopeNoAi($q)
    {
        return $q->where('status', self::STATUS_NO_AI);
    }

    public function scopeExtracted($q)
    {
        return $q->where('status', self::STATUS_EXTRACTED);
    }

    public function scopeSuggested($q)
    {
        return $q->where('status', self::STATUS_SUGGESTED);
    }
}
