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

class EtoroDoctorCommand extends Command
{
    protected $signature = 'etoro:doctor
        {--live : Perform real read-only GET probes against the eToro API}
        {--capture-raw : Persist full raw responses locally (gitignored); may contain personal and financial data}
        {--username= : Use this username for profile/performance/portfolio probes instead of selecting one from rankings}';

    protected $description = 'Validate eToro configuration and, with --live, probe read-only API capability. Never writes or trades.';

    private const PAUSE_BETWEEN_PROBES_SECONDS = 1;

    private const MAX_RATE_LIMIT_WAIT_SECONDS = 60;

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

        if ($this->option('capture-raw')) {
            $this->components->warn(
                'Raw response capture is ON. Files under storage/app/private/etoro/raw may contain '.
                'personal and financial data. They are gitignored — never commit them.'
            );
        }

        $this->runLiveProbes();

        return self::SUCCESS;
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

        foreach ($this->probeDefinitions() as $definition) {
            $this->line(sprintf('  %-32s GET %s', $definition['label'], $definition['path']));
        }
    }

    /**
     * @return list<array{label: string, path: string}>
     */
    private function probeDefinitions(): array
    {
        return [
            ['label' => 'Authenticated profile', 'path' => '/api/v1/me'],
            ['label' => 'Investor rankings', 'path' => '/api/v2/portfolios/rankings'],
            ['label' => 'Public trader profile', 'path' => '/api/v1/user-info/people'],
            ['label' => 'Trader performance history', 'path' => '/api/v1/user-info/people/{username}/gain'],
            ['label' => 'Trader live portfolio', 'path' => '/api/v1/user-info/people/{username}/portfolio/live'],
            ['label' => 'Real account P&L', 'path' => '/api/v1/trading/info/real/pnl'],
            ['label' => 'Demo account P&L', 'path' => '/api/v1/trading/info/demo/pnl'],
        ];
    }

    private function runLiveProbes(): void
    {
        $rows = [];

        ['row' => $meRow] = $this->executeProbe(
            'Authenticated profile',
            '/api/v1/me',
            fn () => $this->client->authenticatedUser(),
            accountLevel: true,
        );
        $rows[] = $meRow;
        $this->pauseBetweenProbes($meRow);

        ['row' => $rankingsRow, 'response' => $rankingsResponse] = $this->executeProbe(
            'Investor rankings',
            '/api/v2/portfolios/rankings',
            fn () => $this->client->rankings(new RankingQuery(period: 'CurrMonth', page: 1, pageSize: 5)),
            accountLevel: true,
        );
        $rows[] = $rankingsRow;
        $this->pauseBetweenProbes($rankingsRow);

        $username = $this->option('username') ?: $this->selectUsernameFromRankings($rankingsResponse);

        if ($username !== null) {
            ['row' => $profileRow] = $this->executeProbe(
                'Public trader profile',
                '/api/v1/user-info/people',
                fn () => $this->client->userProfile($username),
            );
            $rows[] = $profileRow;
            $this->pauseBetweenProbes($profileRow);

            ['row' => $performanceRow] = $this->executeProbe(
                'Trader performance history',
                '/api/v1/user-info/people/{username}/gain',
                fn () => $this->client->userPerformance($username),
            );
            $rows[] = $performanceRow;
            $this->pauseBetweenProbes($performanceRow);

            ['row' => $portfolioRow] = $this->executeProbe(
                'Trader live portfolio',
                '/api/v1/user-info/people/{username}/portfolio/live',
                fn () => $this->client->userLivePortfolio($username),
            );
            $rows[] = $portfolioRow;
            $this->pauseBetweenProbes($portfolioRow);
        } else {
            $reason = 'skipped: no selectable username (rankings unavailable or returned no trader-type result)';
            $rows[] = $this->skipped('Public trader profile', '/api/v1/user-info/people', $reason);
            $rows[] = $this->skipped('Trader performance history', '/api/v1/user-info/people/{username}/gain', $reason);
            $rows[] = $this->skipped('Trader live portfolio', '/api/v1/user-info/people/{username}/portfolio/live', $reason);
        }

        ['row' => $realRow] = $this->executeProbe(
            'Real account P&L',
            '/api/v1/trading/info/real/pnl',
            fn () => $this->client->accountPnl(EtoroEnvironment::Real),
            accountLevel: true,
        );
        $rows[] = $realRow;
        $this->pauseBetweenProbes($realRow);

        ['row' => $demoRow] = $this->executeProbe(
            'Demo account P&L',
            '/api/v1/trading/info/demo/pnl',
            fn () => $this->client->accountPnl(EtoroEnvironment::Demo),
            accountLevel: true,
        );
        $rows[] = $demoRow;

        $this->renderResults($rows);
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
     * @return array{row: array{label: string, path: string, status: ?int, requestId: ?string, durationMs: ?float, classification: CapabilityStatus, note: string, retryAfter: ?int, rateLimitLimit: ?string, rateLimitRemaining: ?string}, response: ?EtoroApiResponse}
     */
    private function executeProbe(string $label, string $path, Closure $call, bool $accountLevel = false): array
    {
        try {
            $response = $call();
        } catch (EtoroConfigurationException) {
            return ['row' => $this->result($label, $path, null, null, null, CapabilityStatus::NotAvailable, 'configuration error'), 'response' => null];
        } catch (EtoroRequestException $exception) {
            return ['row' => $this->fromRequestException($label, $path, $exception, $accountLevel), 'response' => null];
        } catch (EtoroUnexpectedResponseException $exception) {
            return ['row' => $this->result($label, $path, $exception->httpStatus, $exception->requestId, null, CapabilityStatus::UnexpectedSchema, 'response did not decode as expected'), 'response' => null];
        }

        if ($this->option('capture-raw')) {
            $this->captureRaw($label, $response);
        }

        return [
            'row' => $this->result(
                $label,
                $path,
                $response->status,
                $response->requestId,
                $response->durationMs,
                CapabilityStatus::Works,
                $this->summarizeStructure($response->payload),
                retryAfter: isset($response->rateLimitHeaders['Retry-After']) ? (int) $response->rateLimitHeaders['Retry-After'] : null,
                rateLimitLimit: $response->rateLimitHeaders['X-RateLimit-Limit'] ?? null,
                rateLimitRemaining: $response->rateLimitHeaders['X-RateLimit-Remaining'] ?? null,
            ),
            'response' => $response,
        ];
    }

    /**
     * @return array{label: string, path: string, status: ?int, requestId: ?string, durationMs: ?float, classification: CapabilityStatus, note: string, retryAfter: ?int, rateLimitLimit: ?string, rateLimitRemaining: ?string}
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
            default => $exception->category->value,
        };

        return $this->result(
            $label,
            $path,
            $exception->httpStatus,
            $exception->requestId,
            null,
            $classification,
            $note,
            $exception->retryAfterSeconds,
            $exception->rateLimitLimit,
            $exception->rateLimitRemaining,
        );
    }

    /**
     * @return array{label: string, path: string, status: ?int, requestId: ?string, durationMs: ?float, classification: CapabilityStatus, note: string, retryAfter: ?int, rateLimitLimit: ?string, rateLimitRemaining: ?string}
     */
    private function skipped(string $label, string $path, string $note): array
    {
        return $this->result($label, $path, null, null, null, CapabilityStatus::Skipped, $note);
    }

    /**
     * @return array{label: string, path: string, status: ?int, requestId: ?string, durationMs: ?float, classification: CapabilityStatus, note: string, retryAfter: ?int, rateLimitLimit: ?string, rateLimitRemaining: ?string}
     */
    private function result(
        string $label,
        string $path,
        ?int $status,
        ?string $requestId,
        ?float $durationMs,
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
            'durationMs' => $durationMs,
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
     * @param  list<array{label: string, path: string, status: ?int, requestId: ?string, durationMs: ?float, classification: CapabilityStatus, note: string, retryAfter: ?int, rateLimitLimit: ?string, rateLimitRemaining: ?string}>  $rows
     */
    private function renderResults(array $rows): void
    {
        $this->newLine();
        $this->components->info('Live capability probe results (sanitized — no payload values are shown)');

        $this->table(
            ['Capability', 'Method', 'Path', 'Status', 'Latency', 'Classification', 'Note (schema keys only)', 'Request ID', 'RateLimit-Limit', 'RateLimit-Remaining', 'Retry-After'],
            array_map(static fn (array $row): array => [
                $row['label'],
                'GET',
                $row['path'],
                $row['status'] ?? '-',
                $row['durationMs'] !== null ? sprintf('%dms', (int) round($row['durationMs'])) : '-',
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
     * @param  array{label: string, path: string, status: ?int, requestId: ?string, durationMs: ?float, classification: CapabilityStatus, note: string, retryAfter: ?int, rateLimitLimit: ?string, rateLimitRemaining: ?string}  $lastResult
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
