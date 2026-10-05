<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders\Widgets;

use App\Application\Traders\BuildTraderPerformanceReport;
use App\Filament\Support\PercentageDisplay;
use App\Models\Trader;
use Filament\Widgets\ChartWidget;

/**
 * Underwater (drawdown) curve. Prefers the daily series; falls back to the
 * monthly one. The heading always states which granularity is shown.
 */
class TraderDrawdownChart extends ChartWidget
{
    public ?Trader $record = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '240px';

    public function getHeading(): string
    {
        return $this->usesDaily() ? 'Drawdown — DAILY (stored window)' : 'Drawdown — MONTHLY (not intraday)';
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $report = $this->record === null ? null : app(BuildTraderPerformanceReport::class)->handle($this->record);
        $series = $this->usesDaily() ? $report?->daily : $report?->monthly;
        $points = $series->drawdown->drawdownSeries ?? [];
        $format = $this->usesDaily() ? 'Y-m-d' : 'Y-m';

        return [
            'datasets' => [[
                'label' => 'Drawdown (%)',
                'data' => array_map(fn ($point): float => PercentageDisplay::chartValue($point->drawdown), $points),
                'pointRadius' => 0,
                'fill' => true,
            ]],
            'labels' => array_map(fn ($point): string => $point->periodStart->format($format), $points),
        ];
    }

    private function usesDaily(): bool
    {
        return $this->record !== null && app(BuildTraderPerformanceReport::class)->handle($this->record)->daily !== null;
    }
}
