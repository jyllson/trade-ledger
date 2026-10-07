<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Application\Traders\Comparison\BuildTraderComparison;
use App\Application\Traders\Comparison\ComparisonDimension;
use App\Application\Traders\Comparison\ComparisonMetricKey;
use App\Application\Traders\Comparison\DataFreshness;
use App\Application\Traders\Comparison\ProfileCriterion;
use App\Application\Traders\Comparison\TraderComparison;
use App\Application\Traders\Comparison\TraderComparisonCsv;
use App\Application\Traders\Comparison\TraderComparisonEntry;
use App\Application\Traders\Comparison\TraderComparisonRejected;
use App\Filament\Resources\Traders\TraderResource;
use App\Filament\Support\ComparisonDisplay;
use App\Filament\Support\DateTimeDisplay;
use App\Models\AnalysisProfile;
use App\Models\Trader;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Compare 2–10 traders side by side (PROJECT.md Flow E, §14, §15 Compare
 * page, §20 M5; docs/DECISIONS.md D-049).
 *
 * The selection lives in the URL (`?traders=3,1,7&profile=2`) so the page
 * is shareable and survives a reload; it is validated exactly like the
 * read model (BuildTraderComparison: 2–10 distinct, existing traders) plus
 * a syntax check of the ids. Everything shown comes from the read-only
 * comparison read model — rendering never calls eToro and never writes.
 * There is no overall score, ranking or "best" highlight (§14).
 */
class CompareTraders extends Page
{
    protected string $view = 'filament.pages.compare-traders';

    protected static ?string $navigationLabel = 'Compare traders';

    protected static ?string $title = 'Compare traders';

    protected static ?string $slug = 'compare-traders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Research';

    protected static ?int $navigationSort = 2;

    private const string ID_PATTERN = '/^[1-9]\d{0,17}$/';

    /** Comma-separated trader ids in display order (URL `traders`). */
    #[Url(as: 'traders', except: '')]
    public mixed $traders = '';

    /** Analysis profile id (URL `profile`); empty = the default profile. */
    #[Url(as: 'profile', except: '')]
    public mixed $profile = '';

    private ?TraderComparison $comparison = null;

    private bool $resolved = false;

    /** @var array{heading: string, message: string}|null */
    private ?array $rejection = null;

    /**
     * URL of the page for a selection, e.g. from the Traders bulk action.
     *
     * @param  list<int>  $traderIds
     */
    public static function urlFor(array $traderIds): string
    {
        return static::getUrl(['traders' => implode(',', $traderIds)]);
    }

    public function removeTrader(int $position): void
    {
        $tokens = $this->tokens();

        if (! array_key_exists($position, $tokens)) {
            return;
        }

        array_splice($tokens, $position, 1);
        $this->traders = implode(',', $tokens);
        $this->resetComparison();
    }

    public function updatedProfile(): void
    {
        $this->resetComparison();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->addTraderAction(),
            $this->exportCsvAction(),
            Action::make('clearSelection')
                ->label('Clear selection')
                ->icon(Heroicon::OutlinedXMark)
                ->color('gray')
                ->visible(fn (): bool => $this->tokens() !== [])
                ->action(function (): void {
                    $this->traders = '';
                    $this->resetComparison();
                }),
        ];
    }

    private function addTraderAction(): Action
    {
        return Action::make('addTrader')
            ->label('Add trader')
            ->icon(Heroicon::OutlinedPlus)
            ->disabled(fn (): bool => count($this->tokens()) >= BuildTraderComparison::MAXIMUM_TRADERS)
            ->tooltip(fn (): ?string => count($this->tokens()) >= BuildTraderComparison::MAXIMUM_TRADERS
                ? sprintf('At most %d traders can be compared.', BuildTraderComparison::MAXIMUM_TRADERS)
                : null)
            ->modalHeading('Add a trader to the comparison')
            ->modalSubmitActionLabel('Add')
            ->schema([
                Select::make('trader_id')
                    ->label('Trader')
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search): array => Trader::query()
                        ->where('username', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%')
                        ->whereKeyNot($this->selectedIds())
                        ->orderBy('username')
                        ->limit(50)
                        ->pluck('username', 'id')
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => Trader::query()->whereKey($value)->value('username')),
            ])
            ->action(function (array $data): void {
                $id = (int) $data['trader_id'];
                $tokens = $this->tokens();

                if (count($tokens) >= BuildTraderComparison::MAXIMUM_TRADERS) {
                    Notification::make()
                        ->title(sprintf('At most %d traders can be compared.', BuildTraderComparison::MAXIMUM_TRADERS))
                        ->warning()
                        ->send();

                    return;
                }

                if (in_array($id, $this->selectedIds(), true)) {
                    Notification::make()
                        ->title('This trader is already in the comparison.')
                        ->warning()
                        ->send();

                    return;
                }

                if (! Trader::query()->whereKey($id)->exists()) {
                    Notification::make()
                        ->title('This trader does not exist.')
                        ->danger()
                        ->send();

                    return;
                }

                $tokens[] = (string) $id;
                $this->traders = implode(',', $tokens);
                $this->resetComparison();
            });
    }

    private function exportCsvAction(): Action
    {
        return Action::make('exportCsv')
            ->label('Export CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => $this->comparison() !== null)
            ->action(function (TraderComparisonCsv $csv): ?StreamedResponse {
                $comparison = $this->comparison();

                if ($comparison === null) {
                    return null;
                }

                $content = $csv->render($comparison);

                return response()->streamDownload(
                    static function () use ($content): void {
                        echo $content;
                    },
                    $csv->filename($comparison),
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                );
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $comparison = $this->comparison();

        return [
            'chips' => $this->chips(),
            'profiles' => $this->profileOptions(),
            'profileWarning' => $this->profileWarning(),
            'rejection' => $this->rejection,
            'comparison' => $comparison,
            'data' => $comparison === null ? null : $this->presentation($comparison),
        ];
    }

    private function comparison(): ?TraderComparison
    {
        if ($this->resolved) {
            return $this->comparison;
        }

        $this->resolved = true;
        $tokens = $this->tokens();

        if ($tokens === []) {
            return null;
        }

        $invalid = array_values(array_filter($tokens, static fn (string $token): bool => preg_match(self::ID_PATTERN, $token) !== 1));

        if ($invalid !== []) {
            $this->rejection = [
                'heading' => 'The selection in the URL is not valid',
                'message' => sprintf(
                    'Trader ids must be positive whole numbers separated by commas. Not valid: %s.',
                    implode(', ', array_map(static fn (string $token): string => '"'.mb_strimwidth($token, 0, 24, '…').'"', array_slice($invalid, 0, 5))),
                ),
            ];

            return null;
        }

        try {
            $this->comparison = app(BuildTraderComparison::class)->handle(
                array_map(intval(...), $tokens),
                profile: $this->selectedProfile()?->toCriteria(),
            );
        } catch (TraderComparisonRejected $rejected) {
            $this->rejection = [
                'heading' => 'These traders cannot be compared',
                'message' => $rejected->getMessage().($rejected->traderIds === [] ? '' : ' Trader ids: '.implode(', ', $rejected->traderIds).'.'),
            ];
        }

        return $this->comparison;
    }

    private function resetComparison(): void
    {
        $this->resolved = false;
        $this->comparison = null;
        $this->rejection = null;
    }

    /**
     * @return list<string>
     */
    private function tokens(): array
    {
        // A single numeric id may arrive from the URL as an integer.
        $traders = is_int($this->traders) ? (string) $this->traders : $this->traders;

        if (! is_string($traders) || trim($traders) === '') {
            return is_string($traders) || $traders === null ? [] : ['(not a list)'];
        }

        return array_map(trim(...), explode(',', $traders));
    }

    /**
     * @return list<int>
     */
    private function selectedIds(): array
    {
        return array_values(array_map(intval(...), array_filter($this->tokens(), static fn (string $token): bool => preg_match(self::ID_PATTERN, $token) === 1)));
    }

    /**
     * The current selection, one chip per URL token (duplicates and unknown
     * ids included, so each can be removed).
     *
     * @return list<array{position: int, label: string, known: bool, url: ?string}>
     */
    private function chips(): array
    {
        $usernames = Trader::query()->whereKey($this->selectedIds())->pluck('username', 'id');
        $chips = [];

        foreach ($this->tokens() as $position => $token) {
            $username = preg_match(self::ID_PATTERN, $token) === 1 ? $usernames->get((int) $token) : null;

            $chips[] = [
                'position' => $position,
                'label' => is_string($username) ? $username : sprintf('#%s (%s)', mb_strimwidth($token, 0, 24, '…'), preg_match(self::ID_PATTERN, $token) === 1 ? 'not found' : 'invalid'),
                'known' => is_string($username),
                'url' => is_string($username) ? TraderResource::getUrl('view', ['record' => (int) $token]) : null,
            ];
        }

        return $chips;
    }

    private function selectedProfile(): ?AnalysisProfile
    {
        if (! is_string($this->profile) && ! is_int($this->profile)) {
            return null;
        }

        $id = (string) $this->profile;

        return preg_match(self::ID_PATTERN, $id) === 1 ? AnalysisProfile::query()->find((int) $id) : null;
    }

    private function profileWarning(): ?string
    {
        if ($this->profile === '' || $this->profile === null || $this->selectedProfile() !== null) {
            return null;
        }

        return 'The analysis profile in the URL does not exist — the default profile is used instead.';
    }

    /**
     * @return array<int, string>
     */
    private function profileOptions(): array
    {
        return AnalysisProfile::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_default'])
            ->mapWithKeys(fn (AnalysisProfile $profile): array => [$profile->id => $profile->name.($profile->is_default ? ' (default)' : '')])
            ->all();
    }

    /**
     * Everything the Blade view renders, already formatted.
     *
     * @return array<string, mixed>
     */
    private function presentation(TraderComparison $comparison): array
    {
        $entries = $comparison->entries;
        $records = Trader::query()->whereKey(array_map(static fn (TraderComparisonEntry $entry): int => $entry->traderId, $entries))->get()->keyBy('id');

        $traders = array_map(fn (TraderComparisonEntry $entry): array => [
            'id' => $entry->traderId,
            'record' => $records->get($entry->traderId),
            'has_monthly' => $entry->monthlyObservation !== null,
            'username' => $entry->username,
            'url' => TraderResource::getUrl('view', ['record' => $entry->traderId]),
            'monthly' => ComparisonDisplay::series($entry->monthlyObservation),
            'daily' => ComparisonDisplay::series($entry->dailyObservation),
            'snapshot' => $entry->snapshotObservation === null
                ? 'No stored snapshot'
                : 'Captured '.DateTimeDisplay::format($entry->snapshotObservation->from),
        ], $entries);

        $dimensions = [];

        foreach (ComparisonDimension::cases() as $dimension) {
            $rows = [];

            foreach (ComparisonMetricKey::cases() as $key) {
                if ($key->dimension() !== $dimension) {
                    continue;
                }

                $rows[] = [
                    'key' => $key->value,
                    'label' => self::metricLabel($key, $comparison),
                    'hint' => ComparisonDisplay::hint($key),
                    'cells' => array_map(static fn (TraderComparisonEntry $entry): array => ComparisonDisplay::cell($entry->metric($key)), $entries),
                ];
            }

            $dimensions[] = ['label' => $dimension->label(), 'rows' => $rows];
        }

        $filters = [];

        foreach (ProfileCriterion::cases() as $criterion) {
            $first = $entries[0]->profileFilters?->result($criterion);

            $filters[] = [
                'label' => $criterion->label(),
                'threshold' => ComparisonDisplay::threshold($criterion, $first?->threshold),
                'cells' => array_map(
                    static fn (TraderComparisonEntry $entry): ?array => $entry->profileFilters === null ? null : ComparisonDisplay::criterion($entry->profileFilters->result($criterion)),
                    $entries,
                ),
            ];
        }

        $periods = $comparison->periods;

        return [
            'traders' => $traders,
            'dimensions' => $dimensions,
            'filters' => $filters,
            'verdicts' => array_map(static fn (TraderComparisonEntry $entry): ?string => $entry->profileFilters?->verdict->label(), $entries),
            'periodsDiffer' => $periods->observationPeriodsDiffer(),
            'commonMonthly' => $periods->monthly->hasCommonPeriod()
                ? sprintf('%s → %s', $periods->monthly->commonFrom?->format('Y-m'), $periods->monthly->commonTo?->format('Y-m'))
                : null,
            'dataQuality' => $this->dataQualityWarnings($comparison),
            'notSupported' => $this->notSupportedChecks($comparison),
            'profile' => [
                'name' => $comparison->profile->name.($comparison->profile->isBuiltIn() ? ' (built-in default)' : ''),
                'budget' => ComparisonDisplay::criterionValue(ProfileCriterion::TargetCoverageAtBudget, $comparison->profile->budget),
                'target' => ComparisonDisplay::exactPercent($comparison->profile->targetCoverage),
            ],
            'generatedAt' => DateTimeDisplay::format($comparison->generatedAt),
            'methodologyVersion' => $comparison->methodologyVersion,
        ];
    }

    private static function metricLabel(ComparisonMetricKey $key, TraderComparison $comparison): string
    {
        $budget = ComparisonDisplay::criterionValue(ProfileCriterion::TargetCoverageAtBudget, $comparison->profile->budget);

        return match ($key) {
            ComparisonMetricKey::CoverageAtProfileBudget,
            ComparisonMetricKey::SkippedCountAtProfileBudget,
            ComparisonMetricKey::SkippedWeightAtProfileBudget => $key->label().' ('.$budget.')',
            ComparisonMetricKey::MinimumForProfileTarget => $key->label().' ('.ComparisonDisplay::exactPercent($comparison->profile->targetCoverage).')',
            default => $key->label(),
        };
    }

    /**
     * Per-trader data-quality warnings (stale > 48 h, no longer visible /
     * last known snapshot D-045, never synced, no stored data, failed sync
     * runs, incomplete data) — each with the trader it concerns.
     *
     * @return list<array{trader: string, messages: list<string>}>
     */
    private function dataQualityWarnings(TraderComparison $comparison): array
    {
        $warnings = [];

        foreach ($comparison->entries as $entry) {
            $messages = [];
            $staleDetails = $entry->metric(ComparisonMetricKey::StaleDataWarning)->details;
            $performance = self::freshness($staleDetails['performance_freshness'] ?? null);
            $portfolio = self::freshness($staleDetails['portfolio_freshness'] ?? null);
            $performanceSync = ComparisonDisplay::value($entry->metric(ComparisonMetricKey::PerformanceLastSuccessfulSync));
            $portfolioSync = ComparisonDisplay::value($entry->metric(ComparisonMetricKey::PortfolioLastSuccessfulSync));

            $messages[] = match ($performance) {
                DataFreshness::Stale => sprintf('Performance data is stale — last successful sync %s, more than %d h ago.', $performanceSync, $comparison->staleAfterHours),
                DataFreshness::NoLongerVisible => sprintf('Performance is no longer visible (%s) — values come from the last known stored history.', strtolower(ComparisonDisplay::visibility($entry->performanceVisibility))),
                DataFreshness::NeverSynced => 'Performance was never synced successfully.',
                default => null,
            };

            $messages[] = match ($portfolio) {
                DataFreshness::Stale => sprintf('Portfolio snapshot is stale — last successful sync %s, more than %d h ago.', $portfolioSync, $comparison->staleAfterHours),
                DataFreshness::NoLongerVisible => $entry->snapshotObservation === null
                    ? sprintf('Portfolio is %s and no snapshot is stored.', strtolower(ComparisonDisplay::visibility($entry->portfolioVisibility)))
                    : sprintf(
                        'Portfolio is now %s — copyability, concentration and leverage use the last known snapshot (captured %s, last confirmed %s); it may be outdated.',
                        strtolower(ComparisonDisplay::visibility($entry->portfolioVisibility)),
                        DateTimeDisplay::format($entry->snapshotObservation->from),
                        DateTimeDisplay::format($entry->snapshotObservation->to),
                    ),
                DataFreshness::NeverSynced => 'Portfolio was never synced successfully.',
                default => null,
            };

            if ($entry->monthlyObservation === null) {
                $messages[] = 'No stored monthly performance series — performance and consistency metrics are unavailable.';
            }

            if ($entry->snapshotObservation === null && $portfolio !== DataFreshness::NoLongerVisible) {
                $messages[] = 'No stored portfolio snapshot — risk concentration, leverage and copyability are unavailable.';
            }

            $failed = $entry->metric(ComparisonMetricKey::FailedEndpointCount);

            if (is_int($failed->value) && $failed->value > 0) {
                $messages[] = sprintf(
                    '%d failed sync run%s in the last %d days (performance %d, portfolio %d).',
                    $failed->value,
                    $failed->value === 1 ? '' : 's',
                    $comparison->failedRunWindowDays,
                    self::count($failed->details['failed_performance_runs'] ?? null),
                    self::count($failed->details['failed_portfolio_runs'] ?? null),
                );
            }

            $completeness = $entry->metric(ComparisonMetricKey::CompletenessScore);
            $present = $completeness->details['present_count'] ?? null;
            $collectable = $completeness->details['collectable_count'] ?? null;
            $checks = $completeness->details['collectable_checks'] ?? [];

            if (is_int($present) && is_int($collectable) && $present < $collectable && is_array($checks)) {
                $notPresent = array_filter($checks, static fn (string $state): bool => $state !== 'present');

                $messages[] = sprintf(
                    'Data completeness %s — %d of %d collectable checks present (%s).',
                    ComparisonDisplay::value($completeness),
                    $present,
                    $collectable,
                    implode(', ', array_map(static fn (string $check, string $state): string => str_replace('_', ' ', $check).': '.$state, array_keys($notPresent), $notPresent)),
                );
            }

            $messages = array_values(array_filter($messages));

            if ($messages !== []) {
                $warnings[] = ['trader' => $entry->username, 'messages' => $messages];
            }
        }

        return $warnings;
    }

    private static function freshness(mixed $detail): ?DataFreshness
    {
        return is_string($detail) ? DataFreshness::tryFrom($detail) : null;
    }

    private static function count(mixed $detail): int
    {
        return is_int($detail) ? $detail : 0;
    }

    /**
     * The completeness checks this application does not collect — the same
     * for every trader and outside the completeness denominator (D-047).
     *
     * @return list<string>
     */
    private function notSupportedChecks(TraderComparison $comparison): array
    {
        $checks = $comparison->entries[0]->metric(ComparisonMetricKey::CompletenessScore)->details['not_supported_checks'] ?? [];

        return is_array($checks) ? array_map(static fn (string $check): string => str_replace('_', ' ', $check), array_keys($checks)) : [];
    }
}
