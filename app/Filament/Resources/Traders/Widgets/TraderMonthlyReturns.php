<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders\Widgets;

use App\Application\Traders\BuildTraderPerformanceReport;
use App\Filament\Support\PercentageDisplay;
use App\Models\Trader;
use Filament\Widgets\Widget;

/**
 * Year × month table of monthly returns (PROJECT.md §15 "Monthly return
 * table/heatmap"). Partial and in-progress months are marked, not hidden.
 */
class TraderMonthlyReturns extends Widget
{
    public ?Trader $record = null;

    protected string $view = 'filament.resources.traders.widgets.trader-monthly-returns';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{rows: list<array{year: string, months: array<int, array{label: string, sign: int, note: ?string}|null>}>}
     */
    protected function getViewData(): array
    {
        $monthly = $this->record === null ? null : app(BuildTraderPerformanceReport::class)->handle($this->record)->monthly;
        $byYear = [];

        foreach ($monthly->series->periods ?? [] as $period) {
            $year = $period->periodStart->format('Y');
            $byYear[$year] ??= array_fill(1, 12, null);
            $byYear[$year][(int) $period->periodStart->format('n')] = [
                'label' => PercentageDisplay::format($period->return, 1, signed: true),
                'sign' => $period->return->partsPerBillion() <=> 0,
                'note' => $period->isPartialStart ? 'partial' : ($period->isInProgress ? 'in progress' : null),
            ];
        }

        krsort($byYear);

        $rows = [];

        foreach ($byYear as $year => $months) {
            $rows[] = ['year' => (string) $year, 'months' => $months];
        }

        return ['rows' => $rows];
    }
}
