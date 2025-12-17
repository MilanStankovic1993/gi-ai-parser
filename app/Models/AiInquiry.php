<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiInquiry extends Model
{
    protected $table = 'ai_inquiries';
    protected $guarded = [];

    protected $casts = [
        'headers'     => 'array',
        'received_at' => 'datetime',
        'ai_stopped'  => 'boolean',
        'missing_fields' => 'array',
        'suggestions_payload' => 'array',
    ];
    public function inquiry()
    {
        return $this->belongsTo(\App\Models\Inquiry::class, 'inquiry_id');
    }
}
