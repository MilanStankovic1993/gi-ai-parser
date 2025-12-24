<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    protected $table = 'ai_usage_logs';

    protected $fillable = [
        'provider',
        'model',
        'action',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'cost_usd',
        'cost_eur',
        'ai_inquiry_id',
        'used_at',
    ];

    protected $casts = [
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',

        // ✅ decimal cast da se ne gubi preciznost
        'cost_usd' => 'decimal:6',
        'cost_eur' => 'decimal:6',

        'used_at' => 'datetime',
    ];

    public function aiInquiry(): BelongsTo
    {
        return $this->belongsTo(AiInquiry::class, 'ai_inquiry_id');
    }
}
