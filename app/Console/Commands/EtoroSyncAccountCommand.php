<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Account\AccountSyncEnvironment;
use App\Application\Account\QueueEtoroAccountSync;
use App\Application\Account\SyncEtoroAccount;
use App\Application\Account\SyncEtoroAccountStopReason;
use App\Etoro\EtoroEnvironment;
use Illuminate\Console\Command;

/**
 * Queues (default) or runs (--now) a read-only snapshot of the
 * authenticated eToro DEMO account (docs/DECISIONS.md D-051). `--real` is
 * accepted only to refuse it explicitly: Real sync is disabled in code
 * until Demo acceptance (PROJECT.md §20). Never prints positions, amounts
 * or identifiers.
 */
final class EtoroSyncAccountCommand extends Command
{
    protected $signature = 'etoro:sync-account
        {--demo : Sync the DEMO account (virtual money)}
        {--real : Refused — Real account sync is disabled until Demo acceptance}
        {--now : Run synchronously in this process instead of queueing}';

    protected $description = 'Snapshot the own eToro DEMO account: credit, positions, copies (read-only; queued by default).';

    public function __construct(
        private readonly SyncEtoroAccount $syncEtoroAccount,
        private readonly QueueEtoroAccountSync $queueEtoroAccountSync,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $demo = (bool) $this->option('demo');
        $real = (bool) $this->option('real');

        if ($demo === $real) {
            $this->components->error('Pass exactly one of: --demo, --real.');

            return self::INVALID;
        }

        $environment = $demo ? EtoroEnvironment::Demo : EtoroEnvironment::Real;

        if (! AccountSyncEnvironment::isEnabled($environment)) {
            $this->components->error('Real account sync is disabled in code until the owner accepts the Demo account tracking (PROJECT.md §20, D-051). Nothing was synced or queued.');

            return self::FAILURE;
        }

        if (! config('etoro.enabled')) {
            $this->components->warn('eToro integration is disabled (ETORO_ENABLED=false) — nothing was synced or queued.');

            return self::FAILURE;
        }

        if (! $this->option('now')) {
            $this->queueEtoroAccountSync->handle($environment);
            $this->components->info('Queued the DEMO account sync. A queue worker must be running (php artisan queue:work).');

            return self::SUCCESS;
        }

        $result = $this->syncEtoroAccount->handle($environment);

        $this->table(['Environment', 'Result', 'Snapshot', 'Positions', 'Copies', 'Instrument metadata', 'Import run'], [[
            $environment->value,
            $result->stopReason->value,
            $result->snapshot === null ? '-' : ($result->snapshotCreated ? 'new #'.$result->snapshot->id : 'unchanged #'.$result->snapshot->id),
            $result->snapshot === null ? 0 : $result->snapshot->position_count + $result->snapshot->mirror_position_count,
            $result->snapshot->mirror_count ?? 0,
            $result->enrichment->status->value ?? '-',
            $result->importRun->id,
        ]]);

        if ($result->stopReason->isRetryable()) {
            $this->components->warn('Temporarily unavailable (eToro or the local request budget, D-039): nothing waited. Re-run later, or queue the sync instead of --now.');
        }

        return $result->stopReason === SyncEtoroAccountStopReason::Completed ? self::SUCCESS : self::FAILURE;
    }
}
