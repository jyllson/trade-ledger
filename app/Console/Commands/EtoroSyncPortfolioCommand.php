<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Traders\FindStoredTraderByUsername;
use App\Application\Traders\QueueTraderPortfolioSync;
use App\Application\Traders\SyncTraderPortfolio;
use App\Application\Traders\SyncTraderPortfolioStopReason;
use App\Application\Traders\TraderUsername;
use App\Models\Trader;
use App\Models\TraderStatus;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Queues (default) or runs (--now) a read-only live portfolio import for one
 * stored trader or for every watched trader (docs/DECISIONS.md D-038).
 * Never creates a Trader and never prints positions, weights, or instruments.
 */
final class EtoroSyncPortfolioCommand extends Command
{
    protected $signature = 'etoro:sync-portfolio
        {username? : Username of an already stored trader}
        {--watched : Sync every trader with status "watched"}
        {--now : Run synchronously in this process instead of queueing}';

    protected $description = 'Import the live portfolio snapshot for stored eToro traders (read-only; queued by default).';

    public function __construct(
        private readonly FindStoredTraderByUsername $findStoredTraderByUsername,
        private readonly SyncTraderPortfolio $syncTraderPortfolio,
        private readonly QueueTraderPortfolioSync $queueTraderPortfolioSync,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $username = $this->argument('username');
        $watched = (bool) $this->option('watched');

        if (($username === null) === ! $watched) {
            $this->components->error('Pass exactly one of: a username, or --watched.');

            return self::INVALID;
        }

        if (! config('etoro.enabled')) {
            $this->components->warn('eToro integration is disabled (ETORO_ENABLED=false) — nothing was synced or queued.');

            return $watched ? self::SUCCESS : self::FAILURE;
        }

        $traders = $watched ? $this->watchedTraders() : $this->singleTrader((string) $username);

        if ($traders === null) {
            return self::INVALID;
        }

        if ($traders === []) {
            $this->components->info('No watched traders to sync.');

            return self::SUCCESS;
        }

        return $this->option('now') ? $this->runNow($traders) : $this->queue($traders);
    }

    /**
     * @return list<Trader>|null null when the username is invalid or unknown
     */
    private function singleTrader(string $username): ?array
    {
        try {
            $trader = $this->findStoredTraderByUsername->handle(new TraderUsername($username));
        } catch (InvalidArgumentException) {
            $this->components->error('Invalid username.');

            return null;
        }

        if ($trader === null) {
            $this->components->error('No stored trader with that username. Import it via discovery first.');

            return null;
        }

        return [$trader];
    }

    /**
     * @return list<Trader>
     */
    private function watchedTraders(): array
    {
        return array_values(Trader::query()->where('status', TraderStatus::Watched)->orderBy('id')->get()->all());
    }

    /**
     * @param  list<Trader>  $traders
     */
    private function queue(array $traders): int
    {
        foreach ($traders as $trader) {
            $this->queueTraderPortfolioSync->handle($trader);
        }

        $this->components->info(sprintf('Queued portfolio sync for %d trader(s). A queue worker must be running (php artisan queue:work).', count($traders)));

        return self::SUCCESS;
    }

    /**
     * @param  list<Trader>  $traders
     */
    private function runNow(array $traders): int
    {
        $failures = 0;
        $retryable = 0;
        $rows = [];

        foreach ($traders as $trader) {
            $result = $this->syncTraderPortfolio->handle($trader);
            $failures += $result->stopReason === SyncTraderPortfolioStopReason::Completed ? 0 : 1;
            $retryable += $result->stopReason->isRetryable() ? 1 : 0;

            $rows[] = [
                $trader->id,
                $result->stopReason->value,
                $result->snapshot === null ? '-' : ($result->snapshotCreated ? 'new #'.$result->snapshot->id : 'unchanged #'.$result->snapshot->id),
                $result->snapshot->position_count ?? 0,
                $result->enrichment->status->value ?? '-',
                $result->importRun->id,
            ];
        }

        $this->table(['Trader ID', 'Result', 'Snapshot', 'Positions', 'Instrument metadata', 'Import run'], $rows);

        if ($retryable > 0) {
            $this->components->warn('Temporarily unavailable (eToro or the local request budget, D-039): nothing waited. Re-run later, or queue the sync instead of --now.');
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
