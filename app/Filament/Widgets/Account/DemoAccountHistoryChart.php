<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Account;

use App\Etoro\EtoroEnvironment;
use App\Filament\Support\DateTimeDisplay;
use App\Models\AccountSnapshot;
use Filament\Widgets\ChartWidget;

/**
 * Credit and equity of the stored DEMO account snapshots over time
 * (docs/DECISIONS.md D-051). Shown on „My demo account“ only, and only with
 * at least two snapshots. Equity points are null where it was not computable
 * — the line has a gap instead of an invented value.
 */
class DemoAccountHistoryChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    public const MAX_POINTS = 90;

    protected ?string $heading = 'DEMO account over time (virtual money)';

    protected ?string $description = 'One point per stored snapshot (a new snapshot is stored only when the account changed). Times in Europe/Malta.';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    public static function canView(): bool
    {
        return AccountSnapshot::query()->where('environment', EtoroEnvironment::Demo)->count() >= 2;
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
        $snapshots = AccountSnapshot::query()
            ->where('environment', EtoroEnvironment::Demo)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->limit(self::MAX_POINTS)
            ->get()
            ->reverse()
            ->values();

        return [
            'datasets' => [
                [
                    'label' => 'Equity (USD)',
                    'data' => $snapshots->map(fn (AccountSnapshot $snapshot): ?float => self::dollars($snapshot->equity_cents))->all(),
                    'spanGaps' => false,
                ],
                [
                    'label' => 'Credit / available cash balance (USD)',
                    'data' => $snapshots->map(fn (AccountSnapshot $snapshot): ?float => self::dollars($snapshot->credit_cents))->all(),
                ],
            ],
            'labels' => $snapshots->map(fn (AccountSnapshot $snapshot): string => DateTimeDisplay::format($snapshot->captured_at))->all(),
        ];
    }

    /**
     * Chart coordinates only (Chart.js needs numbers); stored values stay
     * integer cents.
     */
    private static function dollars(?int $cents): ?float
    {
        return $cents === null ? null : $cents / 100;
    }
}
