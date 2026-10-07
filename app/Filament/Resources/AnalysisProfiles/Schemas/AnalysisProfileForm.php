<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalysisProfiles\Schemas;

use App\Application\AnalysisProfiles\AnalysisProfileInput;
use App\Application\AnalysisProfiles\AnalysisProfileSettings;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Analysis profile form (PROJECT.md §11, docs/DECISIONS.md D-048). Inputs
 * are USD and percentage points, converted exactly to cents / ppb by
 * AnalysisProfileInput — never through a float. An empty restrictive
 * criterion is not applied. The default flag is not editable here; it
 * moves only through the "Make default" action.
 */
class AnalysisProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profile')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100)
                            ->unique(ignoreRecord: true),
                        self::usd('budget_cents')
                            ->label('Budget')
                            ->required()
                            ->helperText(sprintf(
                                'Amount you would copy one trader with, in USD. Between $%s (eToro minimum copy amount) and $%s (simulator limit).',
                                number_format(intdiv(AnalysisProfileSettings::MINIMUM_BUDGET_CENTS, 100)),
                                number_format(intdiv(AnalysisProfileSettings::MAXIMUM_BUDGET_CENTS, 100)),
                            )),
                        self::percent('target_coverage_ppb', allowZero: false)
                            ->label('Target coverage')
                            ->required()
                            ->helperText('Share of the visible positive position weight the budget must copy (above 0, at most 100). Default 95.'),
                    ])
                    ->columns(3),
                Section::make('Restrictive criteria')
                    ->description('Leave a criterion empty to not apply it. A criterion whose metric is unavailable is reported as unknown — never as pass or fail.')
                    ->schema([
                        self::percent('maximum_drawdown_ppb', allowZero: true)
                            ->label('Maximum drawdown')
                            ->helperText('0–100. Both the daily and the monthly max drawdown must be at most this.'),
                        TextInput::make('maximum_risk_score')
                            ->label('Maximum risk score')
                            ->integer()
                            ->minValue(AnalysisProfileSettings::MINIMUM_RISK_SCORE)
                            ->maxValue(AnalysisProfileSettings::MAXIMUM_RISK_SCORE)
                            ->helperText('eToro risk score 1–10. Not collected by this application yet, so it is always unknown.'),
                        self::percent('maximum_single_position_ppb', allowZero: true)
                            ->label('Maximum single position')
                            ->helperText('0–100. Largest position by instrument, as a share of the invested weight of the latest snapshot.'),
                        TextInput::make('minimum_history_months')
                            ->label('Minimum history')
                            ->integer()
                            ->minValue(AnalysisProfileSettings::MINIMUM_HISTORY_MONTHS)
                            ->maxValue(AnalysisProfileSettings::MAXIMUM_HISTORY_MONTHS)
                            ->suffix('months')
                            ->helperText('Complete calendar months of stored monthly performance (1–600).'),
                        self::percent('minimum_positive_months_ppb', allowZero: true)
                            ->label('Minimum positive months')
                            ->helperText('0–100. Share of complete months with a positive return.'),
                        self::percent('maximum_allocation_per_trader_ppb', allowZero: false)
                            ->label('Maximum allocation per trader')
                            ->helperText('Above 0, at most 100. Share of the budget per trader — informational only: a limit on how you split the budget, not a property of a trader.'),
                    ])
                    ->columns(2),
            ]);
    }

    private static function usd(string $name): TextInput
    {
        return TextInput::make($name)
            ->prefix('$')
            ->inputMode('decimal')
            ->formatStateUsing(static fn (mixed $state): ?string => is_int($state) ? AnalysisProfileInput::formatUsd($state) : null)
            ->dehydrateStateUsing(static fn (mixed $state): ?int => self::blank($state) ? null : AnalysisProfileInput::parseUsd(self::text($state))?->cents())
            ->rules([
                static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                    if (self::blank($value)) {
                        return;
                    }

                    $amount = AnalysisProfileInput::parseUsd(self::text($value));

                    if ($amount === null) {
                        $fail('Enter an amount in USD with at most 2 decimals.');

                        return;
                    }

                    if ($amount->cents() < AnalysisProfileSettings::MINIMUM_BUDGET_CENTS || $amount->cents() > AnalysisProfileSettings::MAXIMUM_BUDGET_CENTS) {
                        $fail(sprintf(
                            'The budget must be between $%s and $%s.',
                            number_format(intdiv(AnalysisProfileSettings::MINIMUM_BUDGET_CENTS, 100)),
                            number_format(intdiv(AnalysisProfileSettings::MAXIMUM_BUDGET_CENTS, 100)),
                        ));
                    }
                },
            ]);
    }

    private static function percent(string $name, bool $allowZero): TextInput
    {
        return TextInput::make($name)
            ->suffix('%')
            ->inputMode('decimal')
            ->formatStateUsing(static fn (mixed $state): ?string => is_int($state) ? AnalysisProfileInput::formatPercent($state) : null)
            ->dehydrateStateUsing(static fn (mixed $state): ?int => self::blank($state) ? null : AnalysisProfileInput::parsePercent(self::text($state))?->partsPerBillion())
            ->rules([
                static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($allowZero): void {
                    if (self::blank($value)) {
                        return;
                    }

                    $percent = AnalysisProfileInput::parsePercent(self::text($value));

                    if ($percent === null) {
                        $fail('Enter a percentage between 0 and 100 with at most 7 decimals.');

                        return;
                    }

                    if (! $allowZero && $percent->isZero()) {
                        $fail('The percentage must be above 0.');
                    }
                },
            ]);
    }

    private static function blank(mixed $state): bool
    {
        return $state === null || (is_string($state) && trim($state) === '');
    }

    /**
     * Livewire may hold a typed number (e.g. from tests); it is read as its
     * decimal text, still parsed exactly.
     */
    private static function text(mixed $state): string
    {
        return is_int($state) || is_float($state) || is_string($state) ? (string) $state : '';
    }
}
