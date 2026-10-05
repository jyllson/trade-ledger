<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders\Schemas;

use App\Analytics\ValueObjects\Percentage;
use App\Application\Traders\BuildTraderPerformanceReport;
use App\Application\Traders\TraderPerformanceReport;
use App\Application\Traders\TraderPerformanceSeriesReport;
use App\Filament\Support\PercentageDisplay;
use App\Models\PerformanceVisibility;
use App\Models\Trader;
use Closure;
use DateTimeImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

/**
 * Performance sections of the trader view (D-036). Every value comes from
 * BuildTraderPerformanceReport (stored points only — no HTTP on render);
 * this class only formats. Each figure is labelled with its granularity
 * and observed period (PROJECT.md Milestone 3 acceptance).
 */
final class TraderPerformanceSection
{
    /**
     * @return list<Section>
     */
    public static function make(): array
    {
        return [
            Section::make('Performance sync')
                ->schema([
                    TextEntry::make('performance_visibility')
                        ->label('Visibility')
                        ->badge()
                        ->state(fn (Trader $record): string => match ($record->performance_visibility) {
                            null => 'Never synced',
                            PerformanceVisibility::Available => 'Available',
                            PerformanceVisibility::Private => 'Private (opted out)',
                            PerformanceVisibility::NotFound => 'Not found',
                        })
                        ->color(fn (Trader $record): string => match ($record->performance_visibility) {
                            null => 'gray',
                            PerformanceVisibility::Available => 'success',
                            PerformanceVisibility::Private, PerformanceVisibility::NotFound => 'warning',
                        }),
                    TextEntry::make('performance_synced_at')
                        ->label('Last successful sync')
                        ->dateTime()
                        ->placeholder('Never synced'),
                ])
                ->columns(2),
            Section::make('Performance — monthly')
                ->description('Monthly gain series (eToro v2, decimal fractions). Cumulative return and drawdown use every month; per-month statistics use complete months only — a partial first month and the month in progress are excluded (D-033). Historical performance is not a promise of future results.')
                ->visible(fn (Trader $record): bool => self::report($record)->monthly !== null)
                ->schema([
                    self::entry('monthly_period', 'Observed period', fn (TraderPerformanceSeriesReport $r): string => self::periodLabel($r)),
                    self::entry('monthly_months', 'Months (complete / total)', fn (TraderPerformanceSeriesReport $r): string => $r->summary->completePeriodCount.' / '.$r->summary->periodCount),
                    self::percentEntry('monthly_cumulative', 'Cumulative return (all months)', fn (TraderPerformanceSeriesReport $r): ?Percentage => $r->summary->cumulativeReturn),
                    self::percentEntry('monthly_trailing_12', 'Trailing 12 complete months', fn (TraderPerformanceSeriesReport $r): ?Percentage => $r->summary->trailing12Return),
                    self::percentEntry('monthly_trailing_24', 'Trailing 24 complete months', fn (TraderPerformanceSeriesReport $r): ?Percentage => $r->summary->trailing24Return),
                    self::percentEntry('monthly_average', 'Average month (arithmetic)', fn (TraderPerformanceSeriesReport $r): ?Percentage => $r->summary->averageReturn),
                    self::percentEntry('monthly_median', 'Median month', fn (TraderPerformanceSeriesReport $r): ?Percentage => $r->summary->medianReturn),
                    self::entry('monthly_positive', 'Positive / negative / flat months', fn (TraderPerformanceSeriesReport $r): string => sprintf('%d / %d / %d (%s positive)', $r->consistency->positiveCount, $r->consistency->negativeCount, $r->consistency->flatCount, PercentageDisplay::format($r->consistency->positiveRatio, 1))),
                    self::entry('monthly_streaks', 'Longest positive / negative streak', fn (TraderPerformanceSeriesReport $r): string => $r->consistency->longestPositiveStreak.' / '.$r->consistency->longestNegativeStreak.' months'),
                    self::entry('monthly_volatility', 'Volatility (monthly / annualized ×√12)', fn (TraderPerformanceSeriesReport $r): string => PercentageDisplay::format($r->consistency->volatility).' / '.PercentageDisplay::format($r->consistency->annualizedVolatility)),
                    self::entry('monthly_best_worst', 'Best / worst month', fn (TraderPerformanceSeriesReport $r): string => PercentageDisplay::format($r->consistency->bestReturn, signed: true).' / '.PercentageDisplay::format($r->consistency->worstReturn, signed: true)),
                    self::entry('monthly_outliers', 'Complete months: all / without best / without best 3', fn (TraderPerformanceSeriesReport $r): string => implode(' / ', [
                        PercentageDisplay::format($r->consistency->completeCumulativeReturn, signed: true),
                        PercentageDisplay::format($r->consistency->returnExcludingBest, signed: true),
                        PercentageDisplay::format($r->consistency->returnExcludingBestThree, signed: true),
                    ])),
                    self::entry('monthly_drawdown', 'Max MONTHLY drawdown (not intraday)', fn (TraderPerformanceSeriesReport $r): string => self::drawdownLabel($r, 'Y-m')),
                ])
                ->columns(2),
            Section::make('Performance — daily')
                ->description('Daily gain series for the stored window only (eToro returns about the latest 1000 days per sync; older stored days are kept).')
                ->visible(fn (Trader $record): bool => self::report($record)->daily !== null)
                ->schema([
                    self::entry('daily_period', 'Observed period', fn (TraderPerformanceSeriesReport $r): string => self::periodLabel($r), daily: true),
                    self::percentEntry('daily_cumulative', 'Cumulative return over this window', fn (TraderPerformanceSeriesReport $r): ?Percentage => $r->summary->cumulativeReturn, daily: true),
                    self::entry('daily_drawdown', 'Max DAILY drawdown', fn (TraderPerformanceSeriesReport $r): string => self::drawdownLabel($r, 'Y-m-d'), daily: true),
                    self::entry('daily_volatility', 'Daily volatility (not annualized)', fn (TraderPerformanceSeriesReport $r): string => PercentageDisplay::format($r->consistency->volatility, 3), daily: true),
                ])
                ->columns(2),
        ];
    }

    public static function report(Trader $record): TraderPerformanceReport
    {
        return app(BuildTraderPerformanceReport::class)->handle($record);
    }

    /**
     * @param  Closure(TraderPerformanceSeriesReport): string  $state
     */
    private static function entry(string $name, string $label, Closure $state, bool $daily = false): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->state(function (Trader $record) use ($state, $daily): ?string {
                $report = self::report($record);
                $series = $daily ? $report->daily : $report->monthly;

                return $series === null ? null : $state($series);
            })
            ->placeholder('—');
    }

    /**
     * @param  Closure(TraderPerformanceSeriesReport): ?Percentage  $value
     */
    private static function percentEntry(string $name, string $label, Closure $value, bool $daily = false): TextEntry
    {
        return self::entry($name, $label, fn (TraderPerformanceSeriesReport $r): string => PercentageDisplay::format($value($r), signed: true), $daily);
    }

    private static function periodLabel(TraderPerformanceSeriesReport $report): string
    {
        $summary = $report->summary;
        $format = $summary->granularity->value === 'daily' ? 'Y-m-d' : 'Y-m';
        $notes = array_filter([
            $summary->hasPartialStart ? 'partial first period' : null,
            $summary->hasInProgressPeriod ? 'last period in progress' : null,
        ]);

        return sprintf(
            '%s → %s%s',
            $summary->firstPeriodStart?->format($format) ?? '—',
            $summary->lastPeriodStart?->format($format) ?? '—',
            $notes === [] ? '' : ' ('.implode(', ', $notes).')',
        );
    }

    private static function drawdownLabel(TraderPerformanceSeriesReport $report, string $format): string
    {
        $drawdown = $report->drawdown;
        $label = PercentageDisplay::format($drawdown->maxDrawdown);

        if ($drawdown->troughPeriodStart === null) {
            return $label;
        }

        $date = static fn (?DateTimeImmutable $value): string => $value?->format($format) ?? 'start';

        return sprintf(
            '%s (peak %s → trough %s, %s)',
            $label,
            $date($drawdown->peakPeriodStart),
            $date($drawdown->troughPeriodStart),
            $drawdown->recoveryPeriodStart === null ? 'not recovered' : 'recovered '.$drawdown->recoveryPeriodStart->format($format),
        );
    }
}
