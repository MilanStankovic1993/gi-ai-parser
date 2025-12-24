<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class AiUsageTracker
{
    /**
     * Fallback cene ako nema config('ai.prices').
     * Format: [prompt_per_1k_usd, completion_per_1k_usd]
     */
    private array $fallbackPrices = [
        // ⚠️ zameni tačnim cenama za tvoje modele
        'gpt-4.1'     => [0.01, 0.03],
        'gpt-4o-mini' => [0.00015, 0.00060],
        'default'     => [0.01, 0.03],
    ];

    public function monthTotalUsd(?Carbon $now = null): float
    {
        $now ??= now();

        return (float) AiUsageLog::query()
            ->whereBetween('used_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
            ->sum('cost_usd');
    }

    public function monthTotalEur(?Carbon $now = null): float
    {
        $now ??= now();

        return (float) AiUsageLog::query()
            ->whereBetween('used_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
            ->sum('cost_eur');
    }

    public function monthTotalUsdByAction(string $action, ?Carbon $now = null): float
    {
        $now ??= now();

        return (float) AiUsageLog::query()
            ->where('action', $action)
            ->whereBetween('used_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
            ->sum('cost_usd');
    }

    public function limitReached(?Carbon $now = null): bool
    {
        $limit = (float) config('ai.monthly_limit_usd', 0);
        if ($limit <= 0) {
            return false; // limit ugašen
        }

        return $this->monthTotalUsd($now) >= $limit;
    }

    /**
     * Vraća cene za dati model.
     * - prvo gleda config('ai.prices')
     * - fallback na internal
     */
    public function pricesFor(string $model): array
    {
        $model = $this->normalizeModel($model);

        $prices = config('ai.prices');
        if (is_array($prices) && isset($prices[$model]) && is_array($prices[$model]) && count($prices[$model]) >= 2) {
            return [(float) $prices[$model][0], (float) $prices[$model][1]];
        }

        if (isset($this->fallbackPrices[$model])) {
            return [(float) $this->fallbackPrices[$model][0], (float) $this->fallbackPrices[$model][1]];
        }

        return [(float) $this->fallbackPrices['default'][0], (float) $this->fallbackPrices['default'][1]];
    }

    /**
     * @param array|null $usage OpenAI usage array: ['prompt_tokens'=>..,'completion_tokens'=>..,'total_tokens'=>..]
     */
    public function log(
        string $model,
        string $action,
        ?array $usage,
        ?int $aiInquiryId = null,
        ?Carbon $usedAt = null,
        string $provider = 'openai'
    ): ?AiUsageLog {
        if (! is_array($usage)) {
            return null;
        }

        $promptTokens     = max(0, (int) Arr::get($usage, 'prompt_tokens', 0));
        $completionTokens = max(0, (int) Arr::get($usage, 'completion_tokens', 0));
        $totalTokens      = max(0, (int) Arr::get($usage, 'total_tokens', $promptTokens + $completionTokens));

        [$pPrompt, $pCompletion] = $this->pricesFor($model);

        $costUsd =
            ($promptTokens / 1000) * $pPrompt +
            ($completionTokens / 1000) * $pCompletion;

        // DB je decimal(12,6) -> držimo 6 decimala
        $costUsd = round($costUsd, 6);

        $usdToEur = (float) config('ai.usd_to_eur', 0);
        $costEur  = $usdToEur > 0 ? round($costUsd * $usdToEur, 6) : null;

        $usedAt ??= now();

        return AiUsageLog::create([
            'provider'          => $provider,
            'model'             => $this->normalizeModel($model),
            'action'            => $action,
            'prompt_tokens'     => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens'      => $totalTokens,
            'cost_usd'          => $costUsd,
            'cost_eur'          => $costEur,
            'ai_inquiry_id'     => $aiInquiryId,
            'used_at'           => $usedAt,
        ]);
    }

    /**
     * UI helper: mesečni rezime za dashboard
     */
    public function monthSummary(?Carbon $now = null): array
    {
        $now ??= now();

        $base = AiUsageLog::query()
            ->whereBetween('used_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);

        $byAction = (clone $base)
            ->selectRaw('action, SUM(cost_usd) as usd, SUM(cost_eur) as eur, SUM(total_tokens) as tokens')
            ->groupBy('action')
            ->orderByDesc('usd')
            ->get()
            ->map(fn ($r) => [
                'action' => (string) $r->action,
                'usd'    => (float) $r->usd,
                'eur'    => $r->eur !== null ? (float) $r->eur : null,
                'tokens' => (int) $r->tokens,
            ])
            ->values()
            ->all();

        return [
            'month' => $now->format('Y-m'),
            'total_usd' => (float) (clone $base)->sum('cost_usd'),
            'total_eur' => (float) (clone $base)->sum('cost_eur'),
            'total_tokens' => (int) (clone $base)->sum('total_tokens'),
            'by_action' => $byAction,
        ];
    }

    private function normalizeModel(string $model): string
    {
        $m = trim($model);
        if ($m === '') return 'default';

        // ako nekad proslediš "gpt-4.1 " ili "GPT-4.1"
        $m = mb_strtolower($m);

        return $m;
    }
}
