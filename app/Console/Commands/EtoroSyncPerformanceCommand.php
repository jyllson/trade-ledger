<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Traders\FindStoredTraderByUsername;
use App\Application\Traders\QueueTraderPerformanceSync;
use App\Application\Traders\SyncTraderPerformance;
use App\Application\Traders\SyncTraderPerformanceStopReason;
use App\Application\Traders\TraderUsername;
use App\Etoro\GainGranularity;
use App\Models\Trader;
use App\Models\TraderStatus;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Queues (default) or runs (--now) a read-only performance sync for one
 * stored trader or for every watched trader (docs/DECISIONS.md D-034).
 * Never creates a Trader and never prints gain values.
 */
final class EtoroSyncPerformanceCommand extends Command
{
    protected $signature = 'etoro:sync-performance
        {username? : Username of an already stored trader}
        {--watched : Sync every trader with status "watched"}
        {--now : Run synchronously in this process instead of queueing}';

    protected $description = 'Sync monthly and daily performance history for stored eToro traders (read-only; queued by default).';

    public function __construct(
        private readonly FindStoredTraderByUsername $findStoredTraderByUsername,
        private readonly SyncTraderPerformance $syncTraderPerformance,
        private readonly QueueTraderPerformanceSync $queueTraderPerformanceSync,
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
            $this->queueTraderPerformanceSync->handle($trader);
        }

        $this->components->info(sprintf('Queued performance sync for %d trader(s). A queue worker must be running (php artisan queue:work).', count($traders)));

        return self::SUCCESS;
    }

    /**
     * @param  list<Trader>  $traders
     */
    private function runNow(array $traders): int
    {
        $failures = 0;
        $rows = [];

        foreach ($traders as $trader) {
            foreach ([GainGranularity::Monthly, GainGranularity::Daily] as $granularity) {
                $result = $this->syncTraderPerformance->handle($trader, $granularity);
                $completed = $result->stopReason === SyncTraderPerformanceStopReason::Completed;
                $failures += $completed ? 0 : 1;

                $rows[] = [$trader->id, $granularity->value, $result->stopReason->value, $result->storedPointCount, $result->importRun->id];

                if (! $completed) {
                    break;
                }
            }
        }

        $this->table(['Trader ID', 'Granularity', 'Result', 'Stored points', 'Import run'], $rows);

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
