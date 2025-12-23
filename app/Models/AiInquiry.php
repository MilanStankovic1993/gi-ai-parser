<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AiInquiry extends Model
{
    protected $table = 'ai_inquiries';

    // Pipeline statusi
    public const STATUS_NEW        = 'new';
    public const STATUS_SYNCED     = 'synced';
    public const STATUS_PARSED     = 'parsed';
    public const STATUS_NEEDS_INFO = 'needs_info';
    public const STATUS_SUGGESTED  = 'suggested';
    public const STATUS_NO_AVAIL   = 'no_availability';
    public const STATUS_NO_AI      = 'no_ai';
    public const STATUS_ERROR      = 'error';

    protected $fillable = [
        'source',
        'message_id',
        'message_hash',

        'from_email',
        'subject',
        'received_at',

        'headers',
        'raw_body',

        'status',
        'ai_stopped',

        // intent + audit
        'intent',
        'out_of_scope_reason',
        'parsed_payload',
        'parse_warnings',

        'inquiry_id',

        'parsed_at',
        'missing_fields',

        'suggestions_payload',
        'suggested_at',
    ];

    protected $casts = [
        'headers'             => 'array',
        'received_at'         => 'datetime',
        'ai_stopped'          => 'boolean',

        'intent'              => 'string',
        'parsed_payload'      => 'array',
        'parse_warnings'      => 'array',

        'missing_fields'      => 'array',
        'suggestions_payload' => 'array',
        'parsed_at'           => 'datetime',
        'suggested_at'        => 'datetime',
    ];

    /**
     * VAŽNO:
     * - Ne stavljamo JSON string default-e ('{}', '[]') na kolone koje su cast-ovane u array.
     * - To ume da napravi "Array to string conversion" i Filament probleme.
     * - Default neka bude null, a u UI/servisima koristimo ?? [] / ?? {} fallback.
     */
    protected $attributes = [
        'ai_stopped' => false,
        'status'     => self::STATUS_NEW,
        'source'     => 'local',

        'parsed_payload'      => null,
        'parse_warnings'      => null,
        'missing_fields'      => null,
        'suggestions_payload' => null,
    ];

    public function inquiry()
    {
        return $this->belongsTo(Inquiry::class, 'inquiry_id');
    }

    // ---- Scopes ----

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('ai_stopped', false);
    }

    public function scopeStopped(Builder $q): Builder
    {
        return $q->where('ai_stopped', true);
    }

    public function scopeStatus(Builder $q, string $status): Builder
    {
        return $q->where('status', $status);
    }

    public function scopePendingSync(Builder $q): Builder
    {
        return $q->whereNull('inquiry_id')->where('status', self::STATUS_NEW);
    }

    public function scopeProcessable(Builder $q): Builder
    {
        return $q->active()->whereNotNull('inquiry_id');
    }

    // ---- Helpers ----

    public function hasSuggestions(): bool
    {
        $payload = $this->suggestions_payload ?? [];

        return ! empty($payload)
            && (
                ! empty($payload['primary'])
                || ! empty($payload['alternatives'])
            );
    }

    public function markStopped(): void
    {
        $this->ai_stopped = true;
        $this->save();
    }
}
