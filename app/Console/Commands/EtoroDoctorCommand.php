<?php

namespace App\Console\Commands;

use App\Etoro\CapabilityStatus;
use App\Etoro\EtoroApiResponse;
use App\Etoro\EtoroClient;
use App\Etoro\EtoroEnvironment;
use App\Etoro\EtoroErrorCategory;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroRequestException;
use App\Etoro\Exceptions\EtoroUnexpectedResponseException;
use App\Etoro\RankingQuery;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use LogicException;

class EtoroDoctorCommand extends Command
{
    protected $signature = 'etoro:doctor
        {--live : Perform real read-only GET probes against the eToro API}
        {--only= : Run exactly one allowlisted capability probe (me, rankings, profile, performance, live-portfolio, real-pnl, demo-pnl)}
        {--capture-raw : Persist full raw responses locally (gitignored); may contain personal and financial data}
        {--username= : Use this username for profile/performance/portfolio probes instead of selecting one from rankings}';

    protected $description = 'Validate eToro configuration and, with --live, probe read-only API capability. Never writes or trades.';

    private const PAUSE_BETWEEN_PROBES_SECONDS = 1;

    private const MAX_RATE_LIMIT_WAIT_SECONDS = 60;

    /**
     * Allowlisted capability slugs for --only, each mapped to its display
     * label, request path (template — path placeholders are never resolved
     * to a real value in output), whether a 403 means insufficient scope on
     * the caller's own account (accountLevel), and whether the probe needs
     * a trader username (and therefore may depend on rankings first).
     *
     * @var array<string, array{label: string, path: string, accountLevel: bool, needsUsername: bool}>
     */
    private const CAPABILITIES = [
        'me' => ['label' => 'Authenticated profile', 'path' => '/api/v1/me', 'accountLevel' => true, 'needsUsername' => false],
        'rankings' => ['label' => 'Investor rankings', 'path' => '/api/v2/portfolios/rankings', 'accountLevel' => true, 'needsUsername' => false],
        'profile' => ['label' => 'Public trader profile', 'path' => '/api/v1/user-info/people', 'accountLevel' => false, 'needsUsername' => true],
        'performance' => ['label' => 'Trader performance history', 'path' => '/api/v1/user-info/people/{username}/gain', 'accountLevel' => false, 'needsUsername' => true],
        'live-portfolio' => ['label' => 'Trader live portfolio', 'path' => '/api/v1/user-info/people/{username}/portfolio/live', 'accountLevel' => false, 'needsUsername' => true],
        'real-pnl' => ['label' => 'Real account P&L', 'path' => '/api/v1/trading/info/real/pnl', 'accountLevel' => true, 'needsUsername' => false],
        'demo-pnl' => ['label' => 'Demo account P&L', 'path' => '/api/v1/trading/info/demo/pnl', 'accountLevel' => true, 'needsUsername' => false],
    ];

    public function __construct(private readonly EtoroClient $client)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->components->info('eToro configuration check');

        $configOk = $this->checkConfiguration();

        if (! $this->option('live')) {
            $this->reportPlannedProbes();

            return $configOk ? self::SUCCESS : self::FAILURE;
        }

        if (! $configOk) {
            $this->components->error('Cannot run live probes: fix configuration first.');

            return self::FAILURE;
        }

        $only = $this->option('only');

        if ($only !== null && ! array_key_exists($only, self::CAPABILITIES)) {
            $this->components->error(sprintf(
                "Invalid --only value '%s'. Allowed values: %s.",
                $only,
                implode(', ', array_keys(self::CAPABILITIES)),
            ));

            return self::FAILURE;
        }

        $username = $this->option('username');

        if ($username !== null && trim($username) === '') {
            $this->components->error('--username, when provided, must not be blank.');

            return self::FAILURE;
        }

        if ($this->option('capture-raw')) {
            if (! $this->rawCaptureEnabledInConfig()) {
                $this->components->error(
                    'Raw response capture requires BOTH --capture-raw AND ETORO_STORE_RAW_RESPONSES=true in '.
                    'configuration. The --capture-raw flag was passed, but capture is not enabled in configuration.'
                );

                return self::FAILURE;
            }

            $this->components->warn(
                'Raw response capture is ON. Files under storage/app/private/etoro/raw may contain '.
                'personal and financial data. They are gitignored — never commit them.'
            );
        }

        $succeeded = $only !== null
            ? $this->runSingleProbe($only)
            : $this->runLiveProbes();

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A capability is considered a successful health check result only when
     * it actually works — partial data still counts, but every other
     * classification (auth/scope/visibility failures, unavailable,
     * unexpected schema, or skipped) does not.
     */
    private function isSuccessful(CapabilityStatus $status): bool
    {
        return in_array($status, [CapabilityStatus::Works, CapabilityStatus::WorksWithPartialData], true);
    }

    /**
     * Raw capture is a double opt-in: both ETORO_STORE_RAW_RESPONSES=true in
     * configuration AND --capture-raw must be present. Config alone never
     * captures anything (the flag is the per-run trigger); the flag alone
     * never captures anything either (config is the permission gate). The
     * local .env is never read or modified by this application — the
     * config value is whatever the developer has already set there.
     */
    private function rawCaptureEnabledInConfig(): bool
    {
        return (bool) config('etoro.store_raw_responses');
    }

    private function checkConfiguration(): bool
    {
        $ok = true;

        $this->line('eToro configuration .............. '.(config('etoro.enabled') ? 'ENABLED' : 'DISABLED'));

        if (blank(config('etoro.api_key'))) {
            $this->line('API key .......................... MISSING');
            $ok = false;
        } else {
            $this->line('API key .......................... present');
        }

        if (blank(config('etoro.user_key'))) {
            $this->line('User key ......................... MISSING');
            $ok = false;
        } else {
            $this->line('User key ......................... present');
        }

        $this->line('Write operations ................. BLOCKED');
        $this->line('Account endpoint (ETORO_ENVIRONMENT) ... '.(string) config('etoro.environment'));

        return $ok && (bool) config('etoro.enabled');
    }

    private function reportPlannedProbes(): void
    {
        $this->newLine();
        $this->components->info('Planned read-only probes (run with --live to execute; nothing is called yet)');

        foreach (self::CAPABILITIES as $meta) {
            $this->line(sprintf('  %-32s GET %s', $meta['label'], $meta['path']));
        }
    }

    /**
     * @return bool true iff every executed (non-skipped) capability's
     *              classification is Works or WorksWithPartialData
     */
    private function runLiveProbes(): bool
    {
        $rows = [];

        ['row' => $meRow] = $this->probeFor('me');
        $rows[] = $meRow;
        $this->pauseBetweenProbes($meRow);

        ['row' => $rankingsRow, 'response' => $rankingsResponse] = $this->probeFor('rankings');
        $rows[] = $rankingsRow;
        $this->pauseBetweenProbes($rankingsRow);

        $username = $this->option('username') ?: $this->selectUsernameFromRankings($rankingsResponse);

        if ($username !== null) {
            foreach (['profile', 'performance', 'live-portfolio'] as $slug) {
                ['row' => $row] = $this->probeFor($slug, $username);
                $rows[] = $row;
                $this->pauseBetweenProbes($row);
            }
        } else {
            $reason = 'skipped: no selectable username (rankings unavailable or returned no trader-type result)';

            foreach (['profile', 'performance', 'live-portfolio'] as $slug) {
                $rows[] = $this->skipped(self::CAPABILITIES[$slug]['label'], self::CAPABILITIES[$slug]['path'], $reason);
            }
        }

        ['row' => $realRow] = $this->probeFor('real-pnl');
        $rows[] = $realRow;
        $this->pauseBetweenProbes($realRow);

        ['row' => $demoRow] = $this->probeFor('demo-pnl');
        $rows[] = $demoRow;

        $this->renderResults($rows);

        foreach ($rows as $row) {
            if ($row['classification'] === CapabilityStatus::Skipped) {
                continue;
            }

            if (! $this->isSuccessful($row['classification'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Runs exactly one allowlisted capability. Username-dependent probes
     * (profile, performance, live-portfolio) call rankings first only to
     * select a username when --username was not given — no other capability
     * is ever probed. Account-level probes (me, rankings, real-pnl,
     * demo-pnl) never trigger any dependency call.
     *
     * @return bool true iff the targeted capability's own classification is
     *              Works or WorksWithPartialData (a skipped or otherwise
     *              unsuccessful targeted probe returns false)
     */
    private function runSingleProbe(string $slug): bool
    {
        $meta = self::CAPABILITIES[$slug];
        $rows = [];
        $username = $this->option('username');

        if ($meta['needsUsername'] && $username === null) {
            ['row' => $rankingsRow, 'response' => $rankingsResponse] = $this->probeFor('rankings');
            $rows[] = $rankingsRow;

            $username = $this->selectUsernameFromRankings($rankingsResponse);

            if ($username === null) {
                $rows[] = $this->skipped(
                    $meta['label'],
                    $meta['path'],
                    'skipped: no selectable username (rankings unavailable or returned no trader-type result)',
                );
                $this->renderResults($rows);

                return false;
            }

            $this->pauseBetweenProbes($rankingsRow);
        }

        ['row' => $row] = $this->probeFor($slug, $username);
        $rows[] = $row;

        $this->renderResults($rows);

        return $this->isSuccessful($row['classification']);
    }

    /**
     * @return array{row: array{label: string, path: string, status: ?int, requestId: ?string, attemptCount: ?int, totalDurationMs: ?float, finalAttemptDurationMs: ?float, classification: CapabilityStatus, note: string, retryAfter: ?int, rateLimitLimit: ?string, rateLimitRemaining: ?string}, response: ?EtoroApiResponse}
     */
    private function probeFor(string $slug, ?string $username = null): array
    {
        $meta = self::CAPABILITIES[$slug];

        $call = match ($slug) {
            'me' => fn () => $this->client->authenticatedUser(),
            'rankings' => fn () => $this->client->rankings(new RankingQuery(period: 'CurrMonth', page: 1, pageSize: 5)),
            'profile' => fn () => $this->client->userProfile($username),
            'performance' => fn () => $this->client->userPerformance($username),
            'live-portfolio' => fn () => $this->client->userLivePortfolio($username),
            'real-pnl' => fn () => $this->client->accountPnl(EtoroEnvironment::Real),
            'demo-pnl' => fn () => $this->client->accountPnl(EtoroEnvironment::Demo),
            default => throw new LogicException("Unknown capability slug '{$slug}'."),
        };

        return $this->executeProbe($meta['label'], $meta['path'], $call, accountLevel: $meta['accountLevel']);
    }

    /**
     * Executes one probe and returns both the sanitized display row and
     * (only on success) the underlying response — kept in memory just long
     * enough to let the rankings probe select a username; never printed.
     *
     * $accountLevel distinguishes the user's own account/identity probes
     * (me, rankings, real/demo P&L) — where a 403 means the granted scopes
     * are insufficient — from public-trader-data probes (profile,
     * performance, live portfolio) — where a 403 is more likely a private or
     * visibility-restricted trader, though insufficient scope cannot yet be
     * ruled out without a live result.
     *
     * @param  Closure(): EtoroApiResponse  $call
     * @return array{row: array{label: string, path: string, status: ?int, requestId: ?string, attemptCount: ?int, totalDurationMs: ?float, finalAttemptDurationMs: ?float, classification: CapabilityStatus, note: string, retryAfter: ?int, rateLimitLimit: ?string, rateLimitRemaining: ?string}, response: ?EtoroApiResponse}
     */
    private function executeProbe(string $label, string $path, Closure $call, bool $accountLevel = false): array
    {
        try {
            $response = $call();
        } catch (EtoroConfigurationException) {
            return ['row' => $this->result($label, $path, null, null, null, null, null, CapabilityStatus::NotAvailable, 'configuration error'), 'response' => null];
        } catch (EtoroRequestException $exception) {
            return ['row' => $this->fromRequestException($label, $path, $exception, $accountLevel), 'response' => null];
        } catch (EtoroUnexpectedResponseException $exception) {
            return ['row' => $this->result($label, $path, $exception->httpStatus, $exception->requestId, null, null, null, CapabilityStatus::UnexpectedSchema, 'response did not decode as expected'), 'response' => null];
        }

        if ($this->option('capture-raw') && $this->rawCaptureEnabledInConfig()) {
            $this->captureRaw($label, $response);
        }

        $note = $this->summarizeStructure($response->payload);

        if ($response->attemptCount > 1) {
            $note = "recovered_after_retry; {$note}";
        }

        return [
            'row' => $this->result(
                $label,
                $path,
                $response->status,
                $response->requestId,
                $response->attemptCount,
                $response->totalDurationMs,
                $response->finalAttemptDurationMs,
                CapabilityStatus::Works,
                $note,
                retryAfter: isset($response->rateLimitHeaders['Retry-After']) ? (int) $response->rateLimitHeaders['Retry-After'] : null,
                rateLimitLimit: $response->rateLimitHeaders['X-RateLimit-Limit'] ?? null,
                rateLimitRemaining: $response->rateLimitHeaders['X-RateLimit-Remaining'] ?? null,
            ),
            'response' => $response,
        ];
    }

    /**
     * @return array{label: string, path: string, status: ?int, requestId: ?string, attemptCount: ?int, totalDurationMs: ?float, finalAttemptDurationMs: ?float, classification: CapabilityStatus, note: string, retryAfter: ?int, rateLimitLimit: ?string, rateLimitRemaining: ?string}
     */
    private function fromRequestException(string $label, string $path, EtoroRequestException $exception, bool $accountLevel): array
    {
        $classification = match ($exception->category) {
            EtoroErrorCategory::Authentication => CapabilityStatus::AuthenticationBlocked,
            EtoroErrorCategory::Authorization => $accountLevel
                ? CapabilityStatus::RequiresAdditionalScope
                : CapabilityStatus::PrivateOrVisibilityDependent,
            EtoroErrorCategory::NotFound, EtoroErrorCategory::Validation => CapabilityStatus::NotAvailable,
            EtoroErrorCategory::RateLimited, EtoroErrorCategory::ServerError, EtoroErrorCategory::ConnectionFailed => CapabilityStatus::TemporarilyUnavailable,
        };

        $note = match (true) {
            $exception->category === EtoroErrorCategory::Authorization && $accountLevel => 'authorization (requires additional scope)',
            $exception->category === EtoroErrorCategory::Authorization => 'authorization (private/visibility-dependent; insufficient scope not yet ruled out)',
            $exception->category === EtoroErrorCategory::ConnectionFailed => $this->transportNote($exception),
            default => $exception->category->value,
        };

        return $this->result(
            $label,
            $path,
            $exception->httpStatus,
            $exception->requestId,
            $exception->attemptCount,
            $exception->totalDurationMs,
            $exception->finalAttemptDurationMs,
            $classification,
            $note,
            $exception->retryAfterSeconds,
            $exception->rateLimitLimit,
            $exception->rateLimitRemaining,
        );
    }

    /**
     * Normalized transport category, optionally with a safe curl error
     * number — never the original exception message, URL, or payload.
     */
    private function transportNote(EtoroRequestException $exception): string
    {
        $reason = $exception->transportReason ?? 'unknown_transport_failure';

        return $exception->transportErrno !== null
            ? "{$reason} (curl errno {$exception->transportErrno})"
            : $reason;
    }

    /**
     * @return array{label: string, path: string, status: ?int, requestId: ?string, attemptCount: ?int, totalDurationMs: ?float, finalAttemptDurationMs: ?float, classification: CapabilityStatus, note: string, retryAfter: ?int, rateLimitLimit: ?string, rateLimitRemaining: ?string}
     */
    private function skipped(string $label, string $path, string $note): array
    {
        return $this->result($label, $path, null, null, null, null, null, CapabilityStatus::Skipped, $note);
    }

    /**
     * @return array{label: string, path: string, status: ?int, requestId: ?string, attemptCount: ?int, totalDurationMs: ?float, finalAttemptDurationMs: ?float, classification: CapabilityStatus, note: string, retryAfter: ?int, rateLimitLimit: ?string, rateLimitRemaining: ?string}
     */
    private function result(
        string $label,
        string $path,
        ?int $status,
        ?string $requestId,
        ?int $attemptCount,
        ?float $totalDurationMs,
        ?float $finalAttemptDurationMs,
        CapabilityStatus $classification,
        string $note,
        ?int $retryAfter = null,
        ?string $rateLimitLimit = null,
        ?string $rateLimitRemaining = null,
    ): array {
        return [
            'label' => $label,
            'path' => $path,
            'status' => $status,
            'requestId' => $requestId,
            'attemptCount' => $attemptCount,
            'totalDurationMs' => $totalDurationMs,
            'finalAttemptDurationMs' => $finalAttemptDurationMs,
            'classification' => $classification,
            'note' => $note,
            'retryAfter' => $retryAfter,
            'rateLimitLimit' => $rateLimitLimit,
            'rateLimitRemaining' => $rateLimitRemaining,
        ];
    }

    /**
     * Selects the first trader-type row from a successful rankings response.
     * Never logged or printed — used only to build subsequent request paths.
     */
    private function selectUsernameFromRankings(?EtoroApiResponse $response): ?string
    {
        if ($response === null) {
            return null;
        }

        $results = $response->payload['results'] ?? null;

        if (! is_array($results)) {
            return null;
        }

        foreach ($results as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'trader' && is_string($item['username'] ?? null) && $item['username'] !== '') {
                return $item['username'];
            }
        }

        return null;
    }

    /**
     * @param  list<array{label: string, path: string, status: ?int, requestId: ?string, attemptCount: ?int, totalDurationMs: ?float, finalAttemptDurationMs: ?float, classification: CapabilityStatus, note: string, retryAfter: ?int, rateLimitLimit: ?string, rateLimitRemaining: ?string}>  $rows
     */
    private function renderResults(array $rows): void
    {
        $this->newLine();
        $this->components->info('Live capability probe results (sanitized — no payload values are shown)');

        $this->table(
            ['Capability', 'Method', 'Path', 'Status', 'Attempts', 'Total Duration', 'Final Attempt Duration', 'Classification', 'Note (schema keys only)', 'Request ID', 'RateLimit-Limit', 'RateLimit-Remaining', 'Retry-After'],
            array_map(static fn (array $row): array => [
                $row['label'],
                'GET',
                $row['path'],
                $row['status'] ?? '-',
                $row['attemptCount'] ?? '-',
                $row['totalDurationMs'] !== null ? sprintf('%dms', (int) round($row['totalDurationMs'])) : '-',
                $row['finalAttemptDurationMs'] !== null ? sprintf('%dms', (int) round($row['finalAttemptDurationMs'])) : '-',
                $row['classification']->value,
                $row['note'],
                $row['requestId'] ?? '-',
                $row['rateLimitLimit'] ?? '-',
                $row['rateLimitRemaining'] ?? '-',
                $row['retryAfter'] ?? '-',
            ], $rows),
        );
    }

    /**
     * @param  array{label: string, path: string, status: ?int, requestId: ?string, attemptCount: ?int, totalDurationMs: ?float, finalAttemptDurationMs: ?float, classification: CapabilityStatus, note: string, retryAfter: ?int, rateLimitLimit: ?string, rateLimitRemaining: ?string}  $lastResult
     */
    private function pauseBetweenProbes(array $lastResult): void
    {
        $seconds = self::PAUSE_BETWEEN_PROBES_SECONDS;

        if ($lastResult['retryAfter'] !== null) {
            $seconds = min(max($lastResult['retryAfter'], self::PAUSE_BETWEEN_PROBES_SECONDS), self::MAX_RATE_LIMIT_WAIT_SECONDS);
        }

        Sleep::usleep($seconds * 1_000_000);
    }

    /**
     * Only key names and array counts — never field values (usernames,
     * balances, names, emails, positions, etc. are never printed).
     *
     * @param  array<string, mixed>  $payload
     */
    private function summarizeStructure(array $payload): string
    {
        $parts = [];

        foreach ($payload as $key => $value) {
            $parts[] = match (true) {
                is_array($value) && array_is_list($value) => sprintf('%s[](%d)', $key, count($value)),
                is_array($value) => sprintf('%s{%s}', $key, implode(',', array_keys($value))),
                default => (string) $key,
            };
        }

        return $parts === [] ? '(empty)' : implode(', ', $parts);
    }

    private function captureRaw(string $label, EtoroApiResponse $response): void
    {
        $key = str($label)->slug()->toString();
        $filename = sprintf('etoro/raw/%s_%s.json', now()->format('Ymd_His'), $key);

        Storage::disk('local')->put($filename, json_encode($response->payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
