<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders\Widgets;

use App\Analytics\Data\ConcentrationDimension;
use App\Analytics\Data\ConcentrationGroup;
use App\Analytics\Data\ConcentrationWarning;
use App\Analytics\Data\ExposureStatus;
use App\Analytics\Data\ExposureUnavailableReason;
use App\Analytics\Data\LeverageExposureResult;
use App\Analytics\ValueObjects\Percentage;
use App\Application\Traders\BuildTraderPortfolioReport;
use App\Application\Traders\TraderPortfolioReport;
use App\Filament\Support\NumberDisplay;
use App\Filament\Support\PercentageDisplay;
use App\Models\PerformanceVisibility;
use App\Models\PortfolioPosition;
use App\Models\Trader;
use Carbon\CarbonInterface;
use Filament\Widgets\Widget;

/**
 * Current portfolio allocation, concentration and leverage of the latest
 * STORED snapshot (PROJECT.md §15 sections 5–6, D-041, D-043). Every figure
 * comes from BuildTraderPortfolioReport; this class only formats.
 */
class TraderPortfolio extends Widget
{
    public ?Trader $record = null;

    protected string $view = 'filament.resources.traders.widgets.trader-portfolio';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $report = $this->record === null ? null : app(BuildTraderPortfolioReport::class)->handle($this->record);

        if ($report === null || $report->snapshot === null || $report->exposure === null) {
            return ['available' => false, 'emptyMessage' => self::emptyMessage($report)];
        }

        $snapshot = $report->snapshot;
        $concentration = $report->exposure->concentration;
        $names = self::instrumentNames($report);

        return [
            'available' => true,
            'snapshot' => [
                'captured_at' => self::time($snapshot->captured_at),
                'last_confirmed_at' => self::time($snapshot->last_confirmed_at),
                'position_count' => $snapshot->position_count,
                'social_trades_count' => $snapshot->social_trades_count,
            ],
            'basis' => [
                'invested' => PercentageDisplay::format($concentration->investedWeight),
                'cash' => $concentration->cashWeight === null ? 'Unknown (not reported)' : PercentageDisplay::format($concentration->cashWeight),
                'unaccounted' => $concentration->unaccountedWeight === null ? 'Unknown (cash not reported)' : PercentageDisplay::format($concentration->unaccountedWeight, 4),
            ],
            'positions' => array_map(self::positionRow(...), $report->positions),
            'dimensions' => [
                self::dimensionRow('By instrument', $concentration->byInstrument, $names),
                self::dimensionRow('By asset class', $concentration->byAssetClass, []),
                self::dimensionRow('By sector', $concentration->bySector, []),
            ],
            'assetClasses' => array_map(
                fn (ConcentrationGroup $group): array => ['label' => $group->key ?? 'Unknown (not classified yet)', 'weight' => PercentageDisplay::format($group->weight), 'positions' => $group->positionCount],
                $concentration->byAssetClass->groups,
            ),
            'leverage' => self::leverageRows($report->exposure->leverage),
            'warnings' => array_map(self::warningMessage(...), $concentration->warnings),
        ];
    }

    private static function emptyMessage(?TraderPortfolioReport $report): string
    {
        $kept = $report !== null && $report->storedSnapshotCount > 0
            ? sprintf(' %d older stored snapshot(s) are kept but not shown, because they no longer describe a visible portfolio.', $report->storedSnapshotCount)
            : '';

        return match ($report?->visibility) {
            PerformanceVisibility::Private => 'The last portfolio sync found this portfolio private (the trader opted out), so no portfolio is shown and the copy simulator is unavailable.'.$kept,
            PerformanceVisibility::NotFound => 'The last portfolio sync did not find this trader on eToro, so no portfolio is shown and the copy simulator is unavailable.'.$kept,
            default => 'No portfolio snapshot stored yet. Use “Sync portfolio”.',
        };
    }

    /**
     * @return array<string, string>
     */
    private static function instrumentNames(TraderPortfolioReport $report): array
    {
        $names = [];

        foreach ($report->positions as $position) {
            $names[$position->external_instrument_id] = self::instrumentLabel($position);
        }

        return $names;
    }

    public static function instrumentLabel(PortfolioPosition $position): string
    {
        $instrument = $position->instrument;
        $name = $instrument->name ?? null;
        $symbol = $instrument->symbol ?? null;

        if ($name === null && $symbol === null) {
            return 'Instrument #'.$position->external_instrument_id.' (metadata pending)';
        }

        return trim(($name ?? '').($symbol === null ? '' : ' ('.$symbol.')'));
    }

    /**
     * @return array{index: int, instrument: string, asset_class: string, side: string, weight: string, leverage: string, opened_at: string}
     */
    private static function positionRow(PortfolioPosition $position): array
    {
        return [
            'index' => $position->position_index + 1,
            'instrument' => self::instrumentLabel($position),
            'asset_class' => $position->instrument->asset_class ?? 'Unknown',
            'side' => match ($position->is_buy) {
                true => 'Buy',
                false => 'Sell',
                null => 'Unknown',
            },
            'weight' => PercentageDisplay::format(Percentage::fromPartsPerBillion($position->weight_ppb), 4),
            'leverage' => $position->leverage === null ? 'Unknown' : $position->leverage.'x',
            'opened_at' => $position->opened_at === null ? '—' : self::time($position->opened_at),
        ];
    }

    /**
     * @param  array<string, string>  $names
     * @return array{label: string, status: string, status_color: string, hhi: string, effective: string, largest: string, top_three: string, note: ?string}
     */
    private static function dimensionRow(string $label, ConcentrationDimension $dimension, array $names): array
    {
        $largest = $dimension->largest;

        return [
            'label' => $label,
            'status' => ucfirst($dimension->status->value),
            'status_color' => self::statusColor($dimension->status),
            'hhi' => PercentageDisplay::format($dimension->hhi),
            'effective' => NumberDisplay::decimal($dimension->effectivePositions),
            'largest' => $largest === null ? '—' : sprintf('%s — %s', $largest->key === null ? 'Unknown' : ($names[$largest->key] ?? $largest->key), PercentageDisplay::format($largest->weight)),
            'top_three' => PercentageDisplay::format($dimension->topThreeWeight),
            'note' => match (true) {
                $dimension->status === ExposureStatus::Partial => sprintf(
                    'Partial: only %s of invested weight is classified (%s unknown, counted as one group) — the figures are an approximation.',
                    PercentageDisplay::format($dimension->classifiedWeight),
                    PercentageDisplay::format($dimension->unclassifiedWeight),
                ),
                $dimension->unavailableReason === ExposureUnavailableReason::ClassificationNotSupported => 'Unavailable: eToro provides no verified sector classification for instruments (D-041).',
                $dimension->unavailableReason === ExposureUnavailableReason::NoData => 'Unavailable: no invested weight is classified yet (instrument metadata pending).',
                $dimension->unavailableReason === ExposureUnavailableReason::NoInvestedWeight => 'Unavailable: the snapshot has no invested weight.',
                default => null,
            },
        ];
    }

    /**
     * @return array{status: string, status_color: string, rows: list<array{label: string, value: string}>}
     */
    private static function leverageRows(LeverageExposureResult $leverage): array
    {
        $rows = [];

        if ($leverage->weightedLeverage !== null) {
            $rows[] = ['label' => 'Weighted leverage Σ(wᵢ × leverageᵢ)', 'value' => NumberDisplay::decimal($leverage->weightedLeverage).'x'];
        } elseif ($leverage->knownLeverageContribution !== null) {
            $rows[] = ['label' => 'Weighted leverage', 'value' => sprintf('Not determinable — %s of invested weight has unknown leverage', PercentageDisplay::format($leverage->unknownLeverageWeight))];
            $rows[] = ['label' => 'Known leverage contribution Σ(wᵢ × leverageᵢ), known positions only', 'value' => NumberDisplay::decimal($leverage->knownLeverageContribution).'x'];
            $rows[] = ['label' => 'Known leverage weight', 'value' => PercentageDisplay::format($leverage->knownLeverageWeight)];
        }

        if ($leverage->leveragedWeight !== null) {
            $rows[] = ['label' => 'Leveraged (> 1x) / unleveraged (1x) / unknown weight', 'value' => implode(' / ', [
                PercentageDisplay::format($leverage->leveragedWeight),
                PercentageDisplay::format($leverage->unleveragedWeight),
                PercentageDisplay::format($leverage->unknownLeverageWeight),
            ])];
        }

        $rows[] = ['label' => 'Max leverage', 'value' => $leverage->maxLeverage === null ? '—' : $leverage->maxLeverage.'x'];
        $rows[] = ['label' => 'Leveraged positions / with known leverage / missing / invalid', 'value' => implode(' / ', [
            $leverage->leveragedPositionCount,
            $leverage->knownLeverageCount,
            $leverage->missingLeverageCount,
            $leverage->invalidLeverageCount,
        ])];

        return [
            'status' => ucfirst($leverage->status->value),
            'status_color' => self::statusColor($leverage->status),
            'rows' => $rows,
        ];
    }

    private static function statusColor(ExposureStatus $status): string
    {
        return match ($status) {
            ExposureStatus::Complete => 'success',
            ExposureStatus::Partial => 'warning',
            ExposureStatus::Unavailable => 'gray',
        };
    }

    private static function warningMessage(ConcentrationWarning $warning): string
    {
        return match ($warning) {
            ConcentrationWarning::MissingPositionWeight => 'At least one position has no weight and is excluded from every weighted figure.',
            ConcentrationWarning::NegativeWeightIgnored => 'At least one position has a negative weight (invalid source data) and is ignored.',
            ConcentrationWarning::AssetClassUnknown => 'Part of the invested weight has no asset class yet (instrument metadata pending) and is shown as “Unknown”.',
            ConcentrationWarning::SectorUnknown => 'Part of the invested weight has no sector.',
            ConcentrationWarning::CashWeightUnknown => 'The snapshot does not report a cash weight, so cash and unaccounted weight are unknown.',
            ConcentrationWarning::NoInvestedWeight => 'The snapshot has no invested weight, so no concentration or leverage figure exists.',
        };
    }

    private static function time(CarbonInterface $time): string
    {
        return $time->copy()->setTimezone('Europe/Malta')->format('Y-m-d H:i T');
    }
}
