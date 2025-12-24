<?php

namespace App\Filament\Widgets;

use App\Models\AiUsageLog;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AiUsageOverview extends StatsOverviewWidget
{
    protected function getHeading(): ?string
    {
        return 'AI usage';
    }

    protected function getStats(): array
    {
        $now = now();

        [$mStart, $mEnd] = [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
        [$dStart, $dEnd] = [$now->copy()->startOfDay(), $now->copy()->endOfDay()];

        $monthUsd = (float) AiUsageLog::query()
            ->whereBetween('used_at', [$mStart, $mEnd])
            ->sum('cost_usd');

        $monthEur = (float) AiUsageLog::query()
            ->whereBetween('used_at', [$mStart, $mEnd])
            ->sum('cost_eur');

        $todayUsd = (float) AiUsageLog::query()
            ->whereBetween('used_at', [$dStart, $dEnd])
            ->sum('cost_usd');

        $totalTokensMonth = (int) AiUsageLog::query()
            ->whereBetween('used_at', [$mStart, $mEnd])
            ->sum('total_tokens');

        return [
            Stat::make('This month', $this->money($monthUsd, $monthEur))
                ->description('Ukupno za ' . $now->format('m/Y')),

            Stat::make('Today', $this->money($todayUsd, null))
                ->description('Današnja potrošnja'),

            Stat::make('Tokens (month)', number_format($totalTokensMonth, 0, ',', '.'))
                ->description('Ukupno tokena ovog meseca'),
        ];
    }

    private function money(float $usd, ?float $eur): string
    {
        $usdTxt = '$' . number_format($usd, 4, '.', '');
        if ($eur !== null && $eur > 0) {
            $eurTxt = '€' . number_format($eur, 4, '.', '');
            return "{$usdTxt} / {$eurTxt}";
        }
        return $usdTxt;
    }
}
