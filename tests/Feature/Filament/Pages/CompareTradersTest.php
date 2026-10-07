<?php

use App\Analytics\Data\ReturnPeriodGranularity;
use App\Application\Traders\Comparison\BuildTraderComparison;
use App\Application\Traders\Comparison\TraderComparisonCsv;
use App\Filament\Pages\CompareTraders;
use App\Filament\Resources\Traders\Pages\ListTraders;
use App\Models\AnalysisProfile;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use App\Models\PerformancePoint;
use App\Models\PerformanceVisibility;
use App\Models\Trader;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

require_once __DIR__.'/../../Application/Traders/CopySimulationFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-10-07 12:00:00');
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create());
});

afterEach(function () {
    Http::assertNothingSent();
    Carbon::setTestNow();
});

/**
 * A fresh, public trader with `$months` monthly points from `$firstMonth`
 * (every month +1 %, the last one in progress at the 2026-10-07 03:00 sync)
 * and, optionally, the copy-simulator fixture snapshot.
 *
 * @param  array<string, mixed>  $attributes
 */
function compareTrader(string $username, string $firstMonth = '2025-10-01', int $months = 13, bool $snapshot = true, array $attributes = []): Trader
{
    $trader = Trader::factory()->create([
        'username' => $username,
        'profile_synced_at' => '2026-10-07 02:00:00',
        'performance_synced_at' => '2026-10-07 03:00:00',
        'performance_visibility' => PerformanceVisibility::Available,
        'portfolio_synced_at' => $snapshot ? '2026-10-07 01:00:00' : null,
        'portfolio_visibility' => $snapshot ? PerformanceVisibility::Available : null,
        ...$attributes,
    ]);

    for ($index = 0; $index < $months; $index++) {
        PerformancePoint::factory()->for($trader)->create([
            'granularity' => ReturnPeriodGranularity::Monthly,
            'period_start' => Carbon::parse($firstMonth)->addMonthsNoOverflow($index)->format('Y-m-d'),
            'gain_ppb' => 10_000_000,
            'synced_at' => '2026-10-07 03:00:00',
        ]);
    }

    if ($snapshot) {
        copySimulationSnapshot($trader, ['captured_at' => '2026-10-07 01:00:00', 'last_confirmed_at' => '2026-10-07 01:00:00']);
    }

    return $trader;
}

/**
 * @param  list<Trader>  $traders
 */
function compareQuery(array $traders): string
{
    return implode(',', array_map(static fn (Trader $trader): int => $trader->id, $traders));
}

/**
 * Visible text of a rendered Livewire component (tags and scripts removed),
 * lower-cased and with whitespace collapsed.
 */
function compareVisibleText(string $html): string
{
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', ' ', $html) ?? '';

    return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)))));
}

it('is in the Research navigation group as "Compare traders"', function () {
    expect(CompareTraders::getNavigationLabel())->toBe('Compare traders')
        ->and(CompareTraders::getNavigationGroup())->toBe('Research');

    $this->get(CompareTraders::getUrl())
        ->assertOk()
        ->assertSee('Compare traders')
        ->assertSee('No traders selected')
        ->assertSee('Select 2–10 traders in Research → Traders');
});

it('opens the comparison from the Traders bulk action only for 2–10 traders', function (int $count, bool $navigates) {
    $traders = Trader::factory()->count($count)->create();

    $component = Livewire::test(ListTraders::class)
        ->callTableBulkAction('compare', $traders);

    if ($navigates) {
        $component->assertRedirect(CompareTraders::urlFor($traders->pluck('id')->sort()->values()->all()));
    } else {
        $component->assertNoRedirect()
            ->assertNotified('Select between 2 and 10 traders to compare.');
    }
})->with([
    'one trader' => [1, false],
    'two traders' => [2, true],
    'ten traders' => [10, true],
    'eleven traders' => [11, false],
]);

it('reads the selection from the URL and renders one table with every dimension', function () {
    $alpha = compareTrader('alpha');
    $bravo = compareTrader('bravo');

    Livewire::withQueryParams(['traders' => compareQuery([$bravo, $alpha])])
        ->test(CompareTraders::class)
        ->assertOk()
        ->assertSeeInOrder(['bravo', 'alpha'])
        ->assertSeeInOrder(['Performance', 'Risk', 'Consistency', 'Copyability', 'Operational / data quality'])
        ->assertSee('Cumulative return')
        ->assertSee('Coverage at profile budget ($500.00)')
        ->assertSee('Data completeness score')
        ->assertSee('Unavailable')
        ->assertSee('Not collected from the data source')
        ->assertSee('2025-10 → 2026-10 · 13 months (last in progress)')
        ->assertSee('Observation periods match')
        ->assertDontSee('These traders cannot be compared');
});

it('rejects an invalid URL selection with the read model reasons', function (Closure $query, string $message) {
    $alpha = compareTrader('alpha', snapshot: false);
    $bravo = compareTrader('bravo', snapshot: false);

    Livewire::withQueryParams(['traders' => $query($alpha, $bravo)])
        ->test(CompareTraders::class)
        ->assertOk()
        ->assertSee($message)
        ->assertDontSee('Cumulative return');
})->with([
    'not a number' => [fn (Trader $a, Trader $b): string => $a->id.',abc', 'Trader ids must be positive whole numbers separated by commas. Not valid: "abc".'],
    'empty id' => [fn (Trader $a, Trader $b): string => $a->id.',,'.$b->id, 'Trader ids must be positive whole numbers'],
    'too few' => [fn (Trader $a, Trader $b): string => (string) $a->id, 'Select at least 2 traders to compare.'],
    'too many' => [fn (Trader $a, Trader $b): string => implode(',', range(1, 11)), 'Select at most 10 traders to compare.'],
    'duplicate' => [fn (Trader $a, Trader $b): string => $a->id.','.$a->id, 'Each trader can be selected only once. Trader ids: '],
    'unknown' => [fn (Trader $a, Trader $b): string => $a->id.',999999', 'Some selected traders do not exist. Trader ids: 999999.'],
]);

it('adds and removes traders on the page and keeps the selection in the URL', function () {
    $alpha = compareTrader('alpha', snapshot: false);
    $bravo = compareTrader('bravo', snapshot: false);
    $charlie = compareTrader('charlie', snapshot: false);

    Livewire::withQueryParams(['traders' => (string) $alpha->id])
        ->test(CompareTraders::class)
        ->assertSee('Select at least 2 traders to compare.')
        ->callAction('addTrader', data: ['trader_id' => $bravo->id])
        ->assertSet('traders', compareQuery([$alpha, $bravo]))
        ->assertSee('Cumulative return')
        ->callAction('addTrader', data: ['trader_id' => $charlie->id])
        ->assertSet('traders', compareQuery([$alpha, $bravo, $charlie]))
        ->callAction('addTrader', data: ['trader_id' => $bravo->id])
        ->assertNotified('This trader is already in the comparison.')
        ->assertSet('traders', compareQuery([$alpha, $bravo, $charlie]))
        ->call('removeTrader', 0)
        ->assertSet('traders', compareQuery([$bravo, $charlie]))
        ->assertSeeInOrder(['bravo', 'charlie'])
        ->callAction('clearSelection')
        ->assertSet('traders', '')
        ->assertSee('No traders selected');
});

it('disables adding a trader once 10 are selected', function () {
    $ids = Trader::factory()->count(10)->create()->pluck('id')->all();

    Livewire::withQueryParams(['traders' => implode(',', $ids)])
        ->test(CompareTraders::class)
        ->assertActionDisabled('addTrader');
});

it('preselects the default profile and recomputes for another profile', function () {
    $alpha = compareTrader('alpha');
    $bravo = compareTrader('bravo');
    $strict = AnalysisProfile::factory()->create([
        'name' => 'Strict 1k',
        'budget_cents' => 100_000,
        'maximum_drawdown_ppb' => 200_000_000,
        'minimum_history_months' => 24,
    ]);

    Livewire::withQueryParams(['traders' => compareQuery([$alpha, $bravo])])
        ->test(CompareTraders::class)
        ->assertSet('profile', '')
        ->assertSee('Default profile')
        ->assertSee('Coverage at profile budget ($500.00)')
        ->set('profile', (string) $strict->id)
        ->assertSee('Coverage at profile budget ($1,000.00)')
        ->assertSee('Profile “Strict 1k”', false)
        ->assertSee('≥ 24 months')
        ->assertSee('≤ 20%')
        ->set('profile', '987654')
        ->assertSee('The analysis profile in the URL does not exist — the default profile is used instead.')
        ->assertSee('Coverage at profile budget ($500.00)');
});

it('warns prominently when observation periods differ, with every trader\'s period', function () {
    $alpha = compareTrader('alpha', '2024-09-01', 26);
    $bravo = compareTrader('bravo', '2025-10-01', 13);
    $charlie = compareTrader('charlie', months: 0, snapshot: false);

    Livewire::withQueryParams(['traders' => compareQuery([$alpha, $bravo, $charlie])])
        ->test(CompareTraders::class)
        ->assertSeeHtml('data-periods-differ="true"')
        ->assertSee('Observation periods differ — values are not over the same window')
        ->assertSee('There is no monthly period common to all traders.')
        ->assertSee('2024-09 → 2026-10 · 26 months (last in progress)')
        ->assertSee('2025-10 → 2026-10 · 13 months (last in progress)')
        ->assertSee('No stored series')
        ->assertSee('No stored snapshot');

    Livewire::withQueryParams(['traders' => compareQuery([$alpha, $bravo])])
        ->test(CompareTraders::class)
        ->assertSee('Common monthly period of all traders: 2025-10 → 2026-10.');
});

it('shows data-quality warnings: stale, private last known snapshot, failed syncs, missing data and completeness', function () {
    $stale = compareTrader('stale', attributes: ['performance_synced_at' => '2026-10-04 03:00:00']);
    $private = compareTrader('private', attributes: ['portfolio_visibility' => PerformanceVisibility::Private]);
    $empty = Trader::factory()->create(['username' => 'empty']);

    ImportRun::factory()->create([
        'type' => 'portfolio',
        'status' => ImportRunStatus::Failed,
        'started_at' => '2026-10-06 03:00:00',
        'metadata' => ['query' => ['trader_id' => $stale->id]],
    ]);

    Livewire::withQueryParams(['traders' => compareQuery([$stale, $private, $empty])])
        ->test(CompareTraders::class)
        ->assertSee('Data-quality warnings')
        ->assertSee('Performance data is stale — last successful sync 2026-10-04 05:00 CEST, more than 48 h ago.')
        ->assertSee('1 failed sync run in the last 7 days (performance 0, portfolio 1).')
        ->assertSee('Portfolio is now private — copyability, concentration and leverage use the last known snapshot (captured 2026-10-07 03:00 CEST, last confirmed 2026-10-07 03:00 CEST); it may be outdated.')
        ->assertSee('Portfolio no longer visible — last known snapshot, may be outdated')
        ->assertSee('Performance was never synced successfully.')
        ->assertSee('No stored monthly performance series')
        ->assertSee('No stored portfolio snapshot')
        ->assertSee('Data completeness 0.00 % — 0 of 4 collectable checks present')
        ->assertSee('Not supported by this application and excluded for every trader: asset history, exposure history, trade info, copier history.');
});

it('shows every profile criterion per trader with threshold, actual value and outcome, and the summary as not a score', function () {
    $alpha = compareTrader('alpha', '2024-09-01', 26);
    $bravo = compareTrader('bravo', snapshot: false);
    $profile = AnalysisProfile::factory()->create([
        'name' => 'History',
        'minimum_history_months' => 24,
        'maximum_risk_score' => 5,
    ]);

    Livewire::withQueryParams(['traders' => compareQuery([$alpha, $bravo]), 'profile' => (string) $profile->id])
        ->test(CompareTraders::class)
        ->assertSee('Analysis profile filters')
        ->assertSeeInOrder(['Target coverage at budget', '≥ 95%', 'Maximum drawdown', 'Not applied', 'Maximum risk score', '≤ 5', 'Minimum history', '≥ 24 months'])
        ->assertSee('Pass')
        ->assertSee('Fail')
        ->assertSee('Unknown')
        ->assertSee('25 complete months of history, at least the minimum of 24.')
        ->assertSee('12 complete months of history, below the minimum of 24.')
        ->assertSee('Summary of outcomes')
        ->assertSee('Derived, unweighted (fail before unknown before pass) — not a score.')
        ->assertSee('At least one applied criterion failed');
});

it('never shows an overall score, rank or winner', function () {
    $alpha = compareTrader('alpha', '2024-09-01', 26);
    $bravo = compareTrader('bravo');

    $html = Livewire::withQueryParams(['traders' => compareQuery([$alpha, $bravo])])
        ->test(CompareTraders::class)
        ->html();

    // Explicitly explained exceptions: the data completeness score (a
    // property of the stored data, formula shown), eToro's own risk score
    // (unavailable, not collected) and the statements that there is none.
    $text = str_replace(
        ['data completeness score', 'risk score', 'not a score', 'no overall score', 'no ranking'],
        '',
        compareVisibleText($html),
    );

    expect($text)->not->toContain('score')
        ->not->toContain('rank')
        ->not->toContain('winner')
        ->not->toContain('best trader')
        ->and(compareVisibleText($html))->toContain('describes the stored data, not the trader: present ÷ collectable checks, each weighted 1');
});

it('renders without any write', function () {
    $alpha = compareTrader('alpha');
    $bravo = compareTrader('bravo', attributes: ['portfolio_visibility' => PerformanceVisibility::Private]);
    $writes = [];

    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $this->get(CompareTraders::getUrl().'?traders='.compareQuery([$alpha, $bravo]))->assertOk()->assertSee('Cumulative return');

    expect($writes)->toBe([]);
});

it('exports the comparison as CSV', function () {
    $alpha = compareTrader('alpha');
    $bravo = compareTrader('bravo');

    Livewire::withQueryParams(['traders' => compareQuery([$alpha, $bravo])])
        ->test(CompareTraders::class)
        ->callAction('exportCsv')
        ->assertFileDownloaded('trader-comparison-20261007-120000Z.csv');
});

it('writes machine-readable CSV values with UTC timestamps, filters and the period rows', function () {
    $alpha = compareTrader('alpha', '2024-09-01', 26);
    $bravo = compareTrader('bravo', snapshot: false);
    $profile = AnalysisProfile::factory()->create(['name' => 'CSV', 'minimum_history_months' => 24]);

    $comparison = app(BuildTraderComparison::class)->handle([$alpha->id, $bravo->id], profile: $profile->toCriteria());
    $csv = app(TraderComparisonCsv::class)->render($comparison);
    $rows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), explode("\n", trim($csv)));
    $header = $rows[0];
    $byKey = static function (string $section, string $key, string $username) use ($rows, $header): array {
        foreach ($rows as $row) {
            $assoc = array_combine($header, $row);

            if ($assoc['section'] === $section && $assoc['key'] === $key && $assoc['trader_username'] === $username) {
                return $assoc;
            }
        }

        throw new RuntimeException("Row {$section}/{$key}/{$username} missing.");
    };

    expect($header)->toBe(TraderComparisonCsv::HEADER)
        ->and($byKey('comparison', 'generated_at', ''))->toMatchArray(['value' => '2026-10-07T12:00:00Z', 'unit' => 'timestamp_utc'])
        ->and($byKey('comparison', 'observation_periods_differ', ''))->toMatchArray(['value' => 'true'])
        ->and($byKey('comparison', 'profile_budget', ''))->toMatchArray(['value' => '500.00', 'unit' => 'usd'])
        ->and($byKey('comparison', 'profile_target_coverage', ''))->toMatchArray(['value' => '0.950000000', 'unit' => 'fraction'])
        ->and($byKey('observation_period', 'monthly_series', 'alpha'))->toMatchArray([
            'observation_basis' => 'return_series',
            'observation_granularity' => 'monthly',
            'observation_from_utc' => '2024-09-01',
            'observation_to_utc' => '2026-10-01',
            'observation_point_count' => '26',
            'includes_in_progress_period' => 'true',
        ])
        ->and($byKey('observation_period', 'latest_snapshot', 'bravo'))->toMatchArray(['status' => 'no_data'])
        ->and($byKey('metric', 'average_monthly_return', 'alpha'))->toMatchArray(['dimension' => 'performance', 'status' => 'available', 'value' => '0.010000000', 'unit' => 'fraction'])
        ->and($byKey('metric', 'minimum_for_95', 'alpha'))->toMatchArray(['unit' => 'usd', 'status' => 'available'])
        ->and($byKey('metric', 'minimum_for_95', 'bravo'))->toMatchArray(['status' => 'unavailable', 'value' => '', 'reason' => 'no_stored_snapshot'])
        ->and($byKey('metric', 'performance_last_successful_sync', 'alpha'))->toMatchArray(['value' => '2026-10-07T03:00:00Z', 'observation_to_utc' => '2026-10-07T03:00:00Z'])
        ->and($byKey('metric', 'risk_score', 'alpha'))->toMatchArray(['status' => 'unavailable', 'reason' => 'not_provided_by_source'])
        ->and($byKey('profile_filter', 'minimum_history_months', 'alpha'))->toMatchArray(['status' => 'pass', 'value' => '25', 'threshold' => '24', 'unit' => 'count'])
        ->and($byKey('profile_filter', 'minimum_history_months', 'bravo'))->toMatchArray(['status' => 'fail', 'value' => '12'])
        ->and($byKey('profile_filter', 'maximum_drawdown', 'alpha'))->toMatchArray(['status' => 'not_applied', 'threshold' => ''])
        ->and($byKey('profile_filter_summary', 'verdict', 'bravo'))->toMatchArray(['status' => 'at_least_one_failed'])
        ->and(strtolower($csv))->not->toContain('overall');

    $max = $byKey('metric', 'monthly_max_drawdown', 'alpha');
    expect($max['value'])->toMatch('/^-?\d+\.\d{9}$/');
});

it('neutralises spreadsheet formulas in CSV text cells but keeps numbers numeric', function () {
    $evil = compareTrader('=HYPERLINK("http://x")', snapshot: false);
    $plus = compareTrader('+cmd', snapshot: false);
    $profile = AnalysisProfile::factory()->create(['name' => '@SUM(A1)']);

    $csv = app(TraderComparisonCsv::class)->render(
        app(BuildTraderComparison::class)->handle([$evil->id, $plus->id], profile: $profile->toCriteria()),
    );

    expect($csv)->toContain("'=HYPERLINK")
        ->toContain("'+cmd")
        ->toContain("'@SUM(A1)")
        ->not->toMatch('/(^|,)"?=HYPERLINK/m')
        ->and(TraderComparisonCsv::cell('-0.125000000'))->toBe('-0.125000000')
        ->and(TraderComparisonCsv::cell('-1+1'))->toBe("'-1+1")
        ->and(TraderComparisonCsv::cell("\tTAB"))->toBe("'\tTAB")
        ->and(TraderComparisonCsv::cell('alpha'))->toBe('alpha');
});
