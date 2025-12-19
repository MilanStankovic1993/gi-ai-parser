<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class AiInquiry extends Model
{
    protected $table = 'ai_inquiries';

    // Pipeline statusi (string u DB, ali držimo konstante zbog konzistentnosti)
    public const STATUS_NEW            = 'new';
    public const STATUS_SYNCED         = 'synced';
    public const STATUS_PARSED         = 'parsed';
    public const STATUS_NEEDS_INFO     = 'needs_info';
    public const STATUS_SUGGESTED      = 'suggested';
    public const STATUS_NO_AVAIL       = 'no_availability';
    public const STATUS_ERROR          = 'error';

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
        'missing_fields'      => 'array',
        'suggestions_payload' => 'array',
        'parsed_at'           => 'datetime',
        'suggested_at'        => 'datetime',
    ];

    protected $attributes = [
        'ai_stopped' => false,
        'status'     => self::STATUS_NEW,
        'source'     => 'local',
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
        return ! empty($this->suggestions_payload)
            && (
                ! empty($this->suggestions_payload['primary'])
                || ! empty($this->suggestions_payload['alternatives'])
            );
    }

    public function markStopped(): void
    {
        $this->ai_stopped = true;
        $this->save();
    }
}
