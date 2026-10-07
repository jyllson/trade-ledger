<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders\Widgets;

/**
 * Small-multiple monthly equity index (start = 100 %) of one trader on the
 * Compare traders page (PROJECT.md §15 "small individual charts"; D-049).
 * Same data as TraderEquityChart — stored points only — with the trader and
 * that trader's own observed period in the heading, because the compared
 * traders' series rarely cover the same months.
 */
class TraderComparisonEquityChart extends TraderEquityChart
{
    /** That trader's monthly observation period, as shown in the comparison. */
    public string $period = '';

    protected ?string $maxHeight = '160px';

    /** One cell of the page's small-multiples grid, not the full row of TraderEquityChart. */
    protected int|string|array $columnSpan = 1;

    public function getHeading(): string
    {
        return $this->record === null ? 'Equity index' : $this->record->username;
    }

    public function getDescription(): string
    {
        return 'Monthly equity index (start = 100 %) · own period: '.($this->period === '' ? 'no stored monthly series' : $this->period);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
