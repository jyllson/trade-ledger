<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders\Widgets;

use App\Analytics\Data\CopySimulationResult;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\Traders\BuildCopySimulationMatrix;
use App\Application\Traders\BuildTraderPortfolioReport;
use App\Application\Traders\CopyAmountPreset;
use App\Application\Traders\CopySimulationInput;
use App\Application\Traders\CopySimulationOutOfRange;
use App\Application\Traders\CopySimulationSettings;
use App\Application\Traders\CoverageTargetPreset;
use App\Application\Traders\SimulateCopyAmount;
use App\Application\Traders\TraderPortfolioReport;
use App\Application\Traders\UnsupportedCopySimulationMethodology;
use App\Filament\Support\DateTimeDisplay;
use App\Filament\Support\NumberDisplay;
use App\Filament\Support\PercentageDisplay;
use App\Models\CopySimulation;
use App\Models\Trader;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

/**
 * Livewire CopyAmountSimulator (PROJECT.md Flow D, §15; D-042, D-043).
 *
 * Every figure is computed from the latest STORED snapshot by
 * SimulateCopyAmount::preview() and BuildCopySimulationMatrix — input
 * changes never call the eToro API. "Save simulation" persists exactly the
 * previewed document through SimulateCopyAmount::handle(). When the
 * portfolio is no longer visible, that snapshot is the last known one and
 * the widget says so above the inputs and on the result (D-045).
 */
class CopyAmountSimulator extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    private const string USD_PATTERN = '/^\d{1,13}(\.\d{1,2})?$/';

    private const int RECENT_SIMULATIONS = 10;

    public ?Trader $record = null;

    /** Copy amount in USD, exact decimal string (at most 2 decimals). */
    public string $amount = '200';

    /** Minimum copied position amount M in USD (§12.1). */
    public string $minimumPositionAmount = '1';

    /** Optional target coverage in percentage points (D-023); empty = none. */
    public string $targetCoverage = '';

    protected string $view = 'filament.resources.traders.widgets.copy-amount-simulator';

    protected int|string|array $columnSpan = 'full';

    public function mount(): void
    {
        $this->amount = self::usdInput(CopyAmountPreset::cases()[0]->amount());
        $this->minimumPositionAmount = self::usdInput(CopySimulationSettings::defaultMinimumPositionAmount());
    }

    public function selectPreset(int $cents): void
    {
        $preset = CopyAmountPreset::tryFrom($cents);

        if ($preset === null) {
            return;
        }

        $this->amount = self::usdInput($preset->amount());
        $this->resetValidation('amount');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['amount', 'minimumPositionAmount', 'targetCoverage'], true)) {
            $this->validateOnly($property);
        }
    }

    public function saveSimulationAction(): Action
    {
        return Action::make('saveSimulation')
            ->label('Save simulation')
            ->icon(Heroicon::OutlinedBookmarkSquare)
            ->action(function (SimulateCopyAmount $simulateCopyAmount): void {
                $this->validate();

                $report = $this->report();
                $snapshot = $report?->snapshot;
                $amount = CopySimulationInput::parseUsd($this->amount);
                $minimum = CopySimulationInput::parseUsd($this->minimumPositionAmount);

                if ($snapshot === null || $amount === null || $minimum === null) {
                    Notification::make()
                        ->title('Nothing was saved — no stored portfolio snapshot.')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $simulation = $simulateCopyAmount->handle($snapshot, $amount, $minimum, $this->target());
                } catch (CopySimulationOutOfRange) {
                    Notification::make()
                        ->title('Nothing was saved — a result is out of range (practically unreachable) for these inputs.')
                        ->danger()
                        ->send();

                    return;
                }

                $stale = $report->isStale()
                    ? ' The snapshot is the last known one of a portfolio that is no longer visible and may be outdated.'
                    : '';

                Notification::make()
                    ->title('Simulation saved')
                    ->body(sprintf('Saved simulation #%d over snapshot #%d (%s). It can be recalculated from the stored snapshot at any time.%s', $simulation->id, $snapshot->id, $simulation->methodology_version, $stale))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, list<string|Closure>>
     */
    protected function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:'.self::USD_PATTERN, self::usdInRange()],
            'minimumPositionAmount' => ['required', 'string', 'regex:'.self::USD_PATTERN, self::usdInRange()],
            'targetCoverage' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && $value !== '' && CopySimulationInput::parseTargetPercent($value) === null) {
                    $fail('The target must be percentage points greater than 0 and at most 100, e.g. 95 or 99.5.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'amount.required' => 'Enter a copy amount in USD.',
            'amount.regex' => 'The copy amount must be a USD amount with at most 2 decimals, e.g. 500 or 500.25.',
            'minimumPositionAmount.required' => 'Enter a minimum position amount in USD.',
            'minimumPositionAmount.regex' => 'The minimum position amount must be a USD amount with at most 2 decimals, e.g. 1 or 0.50.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $report = $this->report();

        $data = [
            'presets' => array_map(fn (CopyAmountPreset $preset): array => ['cents' => $preset->value, 'label' => $preset->label()], CopyAmountPreset::cases()),
            'saved' => $this->savedSimulations(),
            'available' => $report?->snapshot !== null,
            'staleWarning' => $report === null ? null : TraderPortfolio::staleWarning($report),
            'simulation' => null,
            'simulation_out_of_range' => false,
            'matrix' => null,
        ];

        if ($report?->snapshot === null) {
            return $data;
        }

        $amount = CopySimulationInput::parseUsd($this->amount);
        $minimum = CopySimulationInput::parseUsd($this->minimumPositionAmount);
        $targetIsValid = trim($this->targetCoverage) === '' || $this->target() !== null;

        // Inputs above the validation maximum are still rendered (next to the
        // validation error): a figure that does not fit the representable
        // range shows as out of range, never as an exception (D-043).
        if ($minimum !== null && $minimum->isPositive()) {
            $data['matrix'] = $this->matrix($report, $minimum);

            if ($amount !== null && $amount->isPositive() && $targetIsValid) {
                try {
                    $data['simulation'] = $this->simulation(
                        $report,
                        app(SimulateCopyAmount::class)->preview($report->snapshot, $amount, $minimum, $this->target()),
                    );
                } catch (CopySimulationOutOfRange) {
                    $data['simulation_out_of_range'] = true;
                }
            }
        }

        return $data;
    }

    private function report(): ?TraderPortfolioReport
    {
        return $this->record === null ? null : app(BuildTraderPortfolioReport::class)->handle($this->record);
    }

    private function target(): ?Percentage
    {
        $raw = trim($this->targetCoverage);

        return $raw === '' ? null : CopySimulationInput::parseTargetPercent($raw);
    }

    /**
     * @param  array<string, mixed>  $document  SimulateCopyAmount result document
     * @return array<string, mixed>
     */
    private function simulation(TraderPortfolioReport $report, array $document): array
    {
        /** @var array<string, mixed> $summary */
        $summary = $document['summary'];
        /** @var list<array<string, mixed>> $positions */
        $positions = $document['positions'];
        /** @var list<array{code: string, message: string}> $warnings */
        $warnings = $document['warnings'];
        /** @var array<string, mixed>|null $target */
        $target = $document['target'];

        $skipped = [];

        foreach ($positions as $position) {
            if ($position['eligible'] === true) {
                continue;
            }

            $stored = $report->positions[(int) $position['index']] ?? null;

            $skipped[] = [
                'instrument' => $stored === null ? 'Instrument #'.$position['instrument_id'] : TraderPortfolio::instrumentLabel($stored),
                'weight' => self::ppb($position['weight_ppb'], 4),
                'estimated' => NumberDisplay::usd($position['estimated_amount_cents']),
                'copied_from' => NumberDisplay::usd($position['minimum_copy_amount_cents']),
                'reason' => match ($position['skip_reason']) {
                    'below_minimum' => 'Below minimum position amount',
                    'zero_weight' => 'Zero weight',
                    'negative_weight' => 'Negative weight',
                    default => (string) $position['skip_reason'],
                },
                'explanation' => (string) $position['explanation'],
            ];
        }

        return [
            'eligible' => sprintf('%d positions — %s of the portfolio', $summary['eligible_positions_count'], self::ppb($summary['eligible_weight_ppb'])),
            'skipped' => sprintf('%d positions — %s of the portfolio', $summary['skipped_positions_count'], self::ppb($summary['skipped_weight_ppb'])),
            'coverage' => self::ppb($summary['coverage_of_positive_weight_ppb']),
            'cash' => $summary['cash_weight_ppb'] === null ? 'Unknown (not reported)' : self::ppb($summary['cash_weight_ppb']),
            'unknown' => $summary['unknown_weight_ppb'] === null ? 'Unknown (cash not reported)' : self::ppb($summary['unknown_weight_ppb'], 4),
            'is_estimate' => $summary['is_estimate'] === true,
            'warnings' => array_column($warnings, 'message'),
            'target' => $target === null ? null : [
                'label' => self::targetLabel($target['target_coverage_ppb']),
                'minimum' => $target['is_reachable'] === true ? NumberDisplay::usd($target['minimum_amount_cents']) : 'Not reachable — no position has a positive weight',
                'achieved' => self::ppb($target['achieved_coverage_ppb']),
            ],
            'skipped_positions' => $skipped,
        ];
    }

    /**
     * @return array{presets: list<array<string, mixed>>, targets: list<array<string, mixed>>, is_estimate: bool, out_of_range: bool, minimum_position: string, platform_minimum: string}
     */
    private function matrix(TraderPortfolioReport $report, Money $minimum): array
    {
        assert($report->snapshot !== null);
        $matrix = app(BuildCopySimulationMatrix::class)->handle($report->snapshot, $minimum);

        return [
            'presets' => array_map(
                fn (CopyAmountPreset $preset): array => $matrix->presetIsOutOfRange($preset)
                    ? ['label' => $preset->label(), 'out_of_range' => true]
                    : self::presetRow($preset, $matrix->preset($preset)),
                CopyAmountPreset::cases(),
            ),
            'targets' => array_map(function (CoverageTargetPreset $preset) use ($matrix): array {
                if ($matrix->targetIsOutOfRange($preset)) {
                    return ['label' => $preset->label(), 'informational' => $preset->isInformational(), 'out_of_range' => true, 'reachable' => false];
                }

                $target = $matrix->target($preset);

                return [
                    'label' => $preset->label(),
                    'informational' => $preset->isInformational(),
                    'out_of_range' => false,
                    'reachable' => $target->effectiveMinimumCopyAmount !== null,
                    'minimum' => NumberDisplay::usd($target->effectiveMinimumCopyAmount?->cents()),
                    'mathematical' => NumberDisplay::usd($target->mathematicalMinimumCopyAmount?->cents()),
                    'achieved' => PercentageDisplay::format($target->achievedRatio),
                ];
            }, CoverageTargetPreset::cases()),
            'is_estimate' => $matrix->isEstimate,
            'out_of_range' => $matrix->isOutOfRange(),
            'minimum_position' => NumberDisplay::usd($matrix->minimumPositionAmount->cents()),
            'platform_minimum' => NumberDisplay::usd($matrix->platformMinimumCopyAmount->cents()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presetRow(CopyAmountPreset $preset, CopySimulationResult $result): array
    {
        return [
            'label' => $preset->label(),
            'eligible' => $result->coverage->eligibleCount.' / '.PercentageDisplay::format($result->coverage->coveredWeight),
            'skipped' => $result->coverage->skippedCount.' / '.PercentageDisplay::format($result->coverage->skippedWeight),
            'coverage' => PercentageDisplay::format($result->coverageOfPositiveWeight),
            'out_of_range' => false,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function savedSimulations(): array
    {
        if ($this->record === null) {
            return [];
        }

        $simulateCopyAmount = app(SimulateCopyAmount::class);

        return array_values($this->record->copySimulations()
            ->orderByDesc('calculated_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_SIMULATIONS)
            ->get()
            ->map(function (CopySimulation $simulation) use ($simulateCopyAmount): array {
                try {
                    $reproduces = $simulateCopyAmount->reproduces($simulation) ? 'Yes' : 'NO — differs';
                } catch (UnsupportedCopySimulationMethodology) {
                    $reproduces = 'Not checked (other methodology)';
                } catch (CopySimulationOutOfRange) {
                    $reproduces = 'Not reproducible — out of range';
                }

                /** @var array<string, mixed> $summary */
                $summary = $simulation->result['summary'] ?? [];

                return [
                    'id' => $simulation->id,
                    'calculated_at' => DateTimeDisplay::format($simulation->calculated_at),
                    'snapshot' => '#'.$simulation->portfolio_snapshot_id,
                    'amount' => NumberDisplay::usd($simulation->copy_amount_cents),
                    'minimum_position' => NumberDisplay::usd($simulation->minimum_position_amount_cents),
                    'target' => $simulation->target_coverage_ppb === null ? '—' : self::targetLabel($simulation->target_coverage_ppb).' → '.NumberDisplay::usd($simulation->minimum_target_amount_cents),
                    'counts' => $simulation->eligible_positions_count.' / '.$simulation->skipped_positions_count,
                    'coverage' => self::ppb($summary['coverage_of_positive_weight_ppb'] ?? null),
                    'is_estimate' => ($summary['is_estimate'] ?? false) === true,
                    'methodology' => $simulation->methodology_version,
                    'reproduces' => $reproduces,
                ];
            })
            ->all());
    }

    private static function ppb(mixed $ppb, int $decimals = 2): string
    {
        return is_int($ppb) ? PercentageDisplay::format(Percentage::fromPartsPerBillion($ppb), $decimals) : '—';
    }

    /**
     * Exact target percentage without trailing zeros ("95 %", "99.5 %").
     */
    private static function targetLabel(mixed $ppb): string
    {
        $formatted = self::ppb($ppb, 7);

        return str_contains($formatted, '.') ? preg_replace('/\.?0+ %$/', ' %', $formatted) ?? $formatted : $formatted;
    }

    private static function usdInput(Money $money): string
    {
        $cents = $money->cents();

        return $cents % 100 === 0 ? (string) intdiv($cents, 100) : sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * $0 < amount <= CopySimulationInput::MAXIMUM_AMOUNT_CENTS (D-043).
     */
    private static function usdInRange(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $money = is_string($value) ? CopySimulationInput::parseUsd($value) : null;

            if ($money !== null && ! $money->isPositive()) {
                $fail('The amount must be greater than $0.');
            } elseif ($money !== null && CopySimulationInput::exceedsMaximum($money)) {
                $fail('The amount must be at most '.CopySimulationInput::maximumAmountLabel().'.');
            }
        };
    }
}
