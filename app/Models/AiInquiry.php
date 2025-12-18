<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiInquiry extends Model
{
    protected $table = 'ai_inquiries';

    protected $fillable = [
        'inquiry_id',
        'message_id',
        'email_from',
        'email_to',
        'subject',
        'headers',
        'received_at',

        'status',
        'ai_stopped',
        'missing_fields',
        'suggestions_payload',
    ];

    protected $casts = [
        'headers'            => 'array',
        'received_at'        => 'datetime',
        'ai_stopped'         => 'boolean',
        'missing_fields'     => 'array',
        'suggestions_payload'=> 'array',
    ];

    protected $attributes = [
        'ai_stopped' => false,
    ];

    public function inquiry()
    {
        return $this->belongsTo(Inquiry::class, 'inquiry_id');
    }

    public function scopeActive($q)
    {
        return $q->where('ai_stopped', false);
    }
}
