<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\Traders\FindStoredTraderByUsername;
use App\Application\Traders\SimulateCopyAmount;
use App\Application\Traders\TraderUsername;
use App\Models\CopySimulation;
use App\Models\PortfolioSnapshot;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Simulates and stores a copy amount over a STORED portfolio snapshot
 * (docs/DECISIONS.md D-042) — the trader's latest one, or --snapshot.
 * Offline: no HTTP, works with ETORO_ENABLED=false.
 */
final class EtoroSimulateCopyCommand extends Command
{
    protected $signature = 'etoro:simulate-copy
        {username : Username of an already stored trader}
        {amount : Copy amount in USD, e.g. 500 or 500.25}
        {--target= : Optional target coverage in percentage points, e.g. 95 (not a 0-1 fraction)}
        {--minimum-position=1 : Minimum copied position amount in USD}
        {--snapshot= : Stored snapshot id of this trader (default: the latest)}';

    protected $description = 'Simulate a copy amount over a stored portfolio snapshot and save the simulation (offline, no eToro call).';

    public function __construct(
        private readonly FindStoredTraderByUsername $findStoredTraderByUsername,
        private readonly SimulateCopyAmount $simulateCopyAmount,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $copyAmount = $this->parseUsd((string) $this->argument('amount'), 'amount');
        $minimumPositionAmount = $this->parseUsd((string) $this->option('minimum-position'), '--minimum-position');

        if ($copyAmount === null || $minimumPositionAmount === null) {
            return self::INVALID;
        }

        if (! $minimumPositionAmount->isPositive()) {
            $this->components->error('--minimum-position must be greater than 0.');

            return self::INVALID;
        }

        $targetOption = $this->option('target');
        $targetCoverage = null;

        if ($targetOption !== null) {
            $targetCoverage = $this->parseTargetPercent((string) $targetOption);

            if ($targetCoverage === null) {
                return self::INVALID;
            }
        }

        $snapshot = $this->snapshot();

        if ($snapshot === null) {
            return self::FAILURE;
        }

        $simulation = $this->simulateCopyAmount->handle($snapshot, $copyAmount, $minimumPositionAmount, $targetCoverage);

        $this->render($simulation);

        return self::SUCCESS;
    }

    private function snapshot(): ?PortfolioSnapshot
    {
        try {
            $trader = $this->findStoredTraderByUsername->handle(new TraderUsername((string) $this->argument('username')));
        } catch (InvalidArgumentException) {
            $this->components->error('Invalid username.');

            return null;
        }

        if ($trader === null) {
            $this->components->error('No stored trader with that username.');

            return null;
        }

        $snapshotOption = $this->option('snapshot');

        if ($snapshotOption === null) {
            $snapshot = $trader->latestPortfolioSnapshot()->first();

            if ($snapshot === null) {
                $this->components->error('The trader has no stored portfolio snapshot. Run etoro:sync-portfolio first.');
            }

            return $snapshot;
        }

        $snapshot = preg_match('/^[1-9]\d{0,18}$/', (string) $snapshotOption) === 1
            ? $trader->portfolioSnapshots()->whereKey((int) $snapshotOption)->first()
            : null;

        if ($snapshot === null) {
            $this->components->error('No stored snapshot with that id for this trader.');
        }

        return $snapshot;
    }

    /**
     * Exact decimal USD → cents (up to 2 decimals), never through a float.
     */
    private function parseUsd(string $raw, string $label): ?Money
    {
        if (preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $raw, $matches) !== 1) {
            $this->components->error("{$label} must be a USD amount with at most 2 decimals, e.g. 500 or 500.25.");

            return null;
        }

        return Money::fromCents((int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0'));
    }

    /**
     * Same contract as etoro:copy-target (D-023): percentage points, up to 7
     * decimals, 0 < target <= 100.
     */
    private function parseTargetPercent(string $raw): ?Percentage
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,7}))?$/', $raw, $matches) === 1) {
            $ppb = (int) $matches[1] * 10_000_000 + (int) str_pad($matches[2] ?? '', 7, '0');

            if ($ppb > 0 && $ppb <= 1_000_000_000) {
                return Percentage::fromPartsPerBillion($ppb);
            }
        }

        $this->components->error('--target must be percentage points greater than 0 and at most 100, e.g. 95 or 99.5.');

        return null;
    }

    private function render(CopySimulation $simulation): void
    {
        /** @var array{snapshot: array{id: int, captured_at: string}, summary: array<string, mixed>, target: array<string, mixed>|null, positions: list<array<string, mixed>>, warnings: list<array{code: string, message: string}>} $result */
        $result = $simulation->result;
        $summary = $result['summary'];

        $this->components->info(sprintf('Copy simulation #%d (%s)', $simulation->id, $simulation->methodology_version));
        $this->components->twoColumnDetail('Snapshot', sprintf('#%d captured %s', $result['snapshot']['id'], $result['snapshot']['captured_at']));
        $this->components->twoColumnDetail('Copy amount', $this->usd($simulation->copy_amount_cents));
        $this->components->twoColumnDetail('Minimum position', $this->usd($simulation->minimum_position_amount_cents));
        $this->components->twoColumnDetail('Eligible / skipped positions', sprintf('%d / %d', $simulation->eligible_positions_count, $simulation->skipped_positions_count));
        $this->components->twoColumnDetail('Eligible weight', $this->percent($simulation->eligible_weight_ppb));
        $this->components->twoColumnDetail('Skipped weight', $this->percent($simulation->skipped_weight_ppb));
        $this->components->twoColumnDetail('Coverage of visible positions', $this->percent($summary['coverage_of_positive_weight_ppb']));
        $this->components->twoColumnDetail('Cash weight', $this->percent($simulation->cash_weight_ppb));
        $this->components->twoColumnDetail('Unknown weight', $this->percent($summary['unknown_weight_ppb']));
        $this->components->twoColumnDetail('Estimate', $summary['is_estimate'] === true ? 'yes' : 'no');

        if ($result['target'] !== null) {
            $this->components->twoColumnDetail(
                'Minimum for '.$this->percent($result['target']['target_coverage_ppb']).' coverage',
                $this->usd($result['target']['minimum_amount_cents']),
            );
        }

        $skipped = array_values(array_filter($result['positions'], fn (array $position): bool => $position['eligible'] === false));

        if ($skipped !== []) {
            $this->table(
                ['#', 'Position', 'Instrument', 'Weight', 'Reason', 'Explanation'],
                array_map(fn (array $position): array => [
                    $position['index'],
                    $position['position_id'],
                    $position['instrument_id'],
                    $this->percent($position['weight_ppb']),
                    $position['skip_reason'],
                    $position['explanation'],
                ], $skipped),
            );
        }

        foreach ($result['warnings'] as $warning) {
            $this->components->warn($warning['message']);
        }
    }

    private function usd(mixed $cents): string
    {
        if (! is_int($cents)) {
            return 'N/A';
        }

        $digits = str_pad((string) $cents, 3, '0', STR_PAD_LEFT);

        return '$'.number_format((int) substr($digits, 0, -2)).'.'.substr($digits, -2);
    }

    private function percent(mixed $ppb): string
    {
        if (! is_int($ppb)) {
            return 'N/A';
        }

        $digits = str_pad((string) abs($ppb), 8, '0', STR_PAD_LEFT);
        $fraction = rtrim(substr($digits, -7), '0');

        return ($ppb < 0 ? '-' : '').substr($digits, 0, -7).($fraction === '' ? '' : '.'.$fraction).'%';
    }
}
