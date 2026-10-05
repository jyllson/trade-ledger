<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders\Widgets;

use App\Application\Traders\BuildTraderPerformanceReport;
use App\Filament\Support\PercentageDisplay;
use App\Models\Trader;
use Filament\Widgets\ChartWidget;

/**
 * Monthly equity index (start = 100 %), from stored points only.
 */
class TraderEquityChart extends ChartWidget
{
    public ?Trader $record = null;

    protected ?string $heading = 'Equity index — monthly (start = 100 %)';

    protected ?string $description = 'Compounded month by month; the last point may be a month in progress.';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $monthly = $this->record === null ? null : app(BuildTraderPerformanceReport::class)->handle($this->record)->monthly;
        $curve = $monthly->summary->equityCurve ?? [];

        return [
            'datasets' => [[
                'label' => 'Equity index (%)',
                'data' => array_map(fn ($point): float => PercentageDisplay::chartValue($point->equityIndex), $curve),
                'pointRadius' => 0,
            ]],
            'labels' => array_map(fn ($point): string => $point->periodStart->format('Y-m'), $curve),
        ];
    }
}
