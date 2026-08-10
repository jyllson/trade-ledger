<?php

namespace App\Console\Commands;

use App\Analytics\Data\CoverageWarning;
use App\Analytics\Exceptions\CoverageCalculationException;
use App\Analytics\ValueObjects\Money;
use App\Analytics\ValueObjects\Percentage;
use App\Application\Etoro\FindTraderMinimumCopyAmountForCoverage;
use App\Application\Etoro\FindTraderMinimumCopyAmountForCoverageResult;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Exceptions\EtoroRequestException;
use App\Etoro\Exceptions\EtoroUnexpectedResponseException;
use Illuminate\Console\Command;

final class EtoroCopyTargetCommand extends Command
{
    protected $signature = 'etoro:copy-target
        {trader-username : eToro trader username to evaluate}
        {target-coverage-percent : Target coverage as PERCENTAGE POINTS decimal (e.g. 95, 95.5, 0.05) — never a 0-1 fraction}
        {minimum-position-cents : Minimum copied-position amount in integer cents}
        {platform-minimum-copy-cents : Platform minimum total copy amount in integer cents}';

    protected $description = "Find the minimum copy amount needed to reach a target portfolio-weight coverage for a trader's live portfolio. Read-only, never writes or trades.";

    public function __construct(private readonly FindTraderMinimumCopyAmountForCoverage $useCase)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $traderUsername = (string) $this->argument('trader-username');

        if (trim($traderUsername) === '') {
            $this->components->error('trader-username must not be blank.');

            return self::FAILURE;
        }

        $targetCoveragePpb = $this->parseTargetCoveragePercent((string) $this->argument('target-coverage-percent'));

        if ($targetCoveragePpb === null) {
            return self::FAILURE;
        }

        $minimumPositionCents = $this->parseCents((string) $this->argument('minimum-position-cents'), 'minimum-position-cents');

        if ($minimumPositionCents === null) {
            return self::FAILURE;
        }

        if ($minimumPositionCents <= 0) {
            $this->components->error('minimum-position-cents must be strictly positive.');

            return self::FAILURE;
        }

        $platformMinimumCopyCents = $this->parseCents((string) $this->argument('platform-minimum-copy-cents'), 'platform-minimum-copy-cents');

        if ($platformMinimumCopyCents === null) {
            return self::FAILURE;
        }

        if ($platformMinimumCopyCents < 0) {
            $this->components->error('platform-minimum-copy-cents must not be negative.');

            return self::FAILURE;
        }

        $targetCoverage = Percentage::fromPartsPerBillion($targetCoveragePpb);
        $minimumPositionAmount = Money::fromCents($minimumPositionCents);
        $platformMinimumCopyAmount = Money::fromCents($platformMinimumCopyCents);

        try {
            $result = $this->useCase->handle(
                traderUsername: $traderUsername,
                targetCoverage: $targetCoverage,
                minimumPositionAmount: $minimumPositionAmount,
                platformMinimumCopyAmount: $platformMinimumCopyAmount,
            );
        } catch (EtoroConfigurationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (EtoroRequestException $exception) {
            $this->components->error($this->formatRequestExceptionMessage($exception));

            return self::FAILURE;
        } catch (EtoroUnexpectedResponseException|EtoroMappingException|CoverageCalculationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderSummary($result, $minimumPositionCents, $platformMinimumCopyCents);

        return self::SUCCESS;
    }

    /**
     * Exact, string-only percentage-points parser: `95` means 95%, `0.05`
     * means 0.05%, never a 0-1 fraction. No float/intval/round/ceil before
     * range validation, no scientific notation, no locale-dependent
     * parsing — only regex syntax validation followed by BCMath decimal
     * composition. Returns null (after printing a sanitized error) for any
     * syntactically or semantically invalid input.
     */
    private function parseTargetCoveragePercent(string $raw): ?int
    {
        if (preg_match('/^\d{1,3}(?:\.\d{1,7})?$/', $raw) !== 1) {
            $this->components->error('target-coverage-percent must be a percentage-points decimal (e.g. 95, 95.5, 0.05 — not a 0-1 fraction), matching ^\\d{1,3}(\\.\\d{1,7})?$.');

            return null;
        }

        $parts = explode('.', $raw, 2);
        $wholePart = $parts[0];
        $fractionPart = $parts[1] ?? '';

        $fractionPadded = str_pad($fractionPart, 7, '0', STR_PAD_RIGHT);

        // The regex above already guarantees $wholePart and $fractionPadded
        // contain only digits (never empty, never a sign or separator), so
        // these checks cannot actually fail — they exist so this digit-only
        // guarantee is provable to static analysis, not runtime behavior.
        if (! is_numeric($wholePart) || ! is_numeric($fractionPadded)) {
            $this->components->error('target-coverage-percent must be a percentage-points decimal (e.g. 95, 95.5, 0.05 — not a 0-1 fraction), matching ^\\d{1,3}(\\.\\d{1,7})?$.');

            return null;
        }

        $ppbDecimal = bcadd(bcmul($wholePart, '10000000', 0), $fractionPadded, 0);

        if (bccomp($ppbDecimal, '0', 0) <= 0 || bccomp($ppbDecimal, '1000000000', 0) > 0) {
            $this->components->error('target-coverage-percent must be strictly greater than 0 and must not exceed 100.');

            return null;
        }

        return (int) $ppbDecimal;
    }

    /**
     * Mirrors EtoroCopyCoverageCommand::parseCents(): accepts only
     * `^-?\d+$` syntax, then confirms the value is representable as a PHP
     * int via exact BCMath bounds comparison before ever casting — an
     * overflow here must fail loudly, never silently wrap or truncate.
     */
    private function parseCents(string $raw, string $label): ?int
    {
        if (preg_match('/^-?\d+$/', $raw) !== 1 || ! is_numeric($raw)) {
            $this->components->error("{$label} must be an integer number of cents (matching ^-?\\d+\$).");

            return null;
        }

        if (bccomp($raw, (string) PHP_INT_MAX, 0) > 0 || bccomp($raw, (string) PHP_INT_MIN, 0) < 0) {
            $this->components->error("{$label} is outside the representable integer range.");

            return null;
        }

        return (int) $raw;
    }

    /**
     * Only sanitized EtoroRequestException metadata — category, http status,
     * request id, and normalized transport reason/errno — never the original
     * transport exception message, URL, or payload.
     */
    private function formatRequestExceptionMessage(EtoroRequestException $exception): string
    {
        $parts = ["eToro request failed: {$exception->category->value}"];

        if ($exception->httpStatus !== null) {
            $parts[] = "http_status={$exception->httpStatus}";
        }

        if ($exception->requestId !== null) {
            $parts[] = "request_id={$exception->requestId}";
        }

        if ($exception->transportReason !== null) {
            $parts[] = "transport_reason={$exception->transportReason}";
        }

        if ($exception->transportErrno !== null) {
            $parts[] = "transport_errno={$exception->transportErrno}";
        }

        return implode(' ', $parts).'.';
    }

    private function renderSummary(FindTraderMinimumCopyAmountForCoverageResult $result, int $minimumPositionCents, int $platformMinimumCopyCents): void
    {
        $coverageTarget = $result->coverageTarget;

        $this->components->info('eToro copy target');

        $this->components->twoColumnDetail('Trader username', $result->traderUsername);
        $this->components->twoColumnDetail('Request ID', $result->requestId);
        $this->components->twoColumnDetail('Target coverage', $this->formatPercentage($coverageTarget->targetRatio->partsPerBillion()));
        $this->components->twoColumnDetail('Minimum position', $this->formatMoney($minimumPositionCents));
        $this->components->twoColumnDetail('Platform minimum copy', $this->formatMoney($platformMinimumCopyCents));
        $this->components->twoColumnDetail('Mathematical minimum copy', $this->formatOptionalMoney($coverageTarget->mathematicalMinimumCopyAmount));
        $this->components->twoColumnDetail('Effective minimum copy', $this->formatOptionalMoney($coverageTarget->effectiveMinimumCopyAmount));
        $this->components->twoColumnDetail('Achieved coverage', $this->formatOptionalPercentage($coverageTarget->achievedRatio));
        $this->components->twoColumnDetail('Covered observed weight', $this->formatOptionalPercentage($coverageTarget->coveredRawWeight));
        $this->components->twoColumnDetail('Incomplete source data', $coverageTarget->hasIncompleteSourceData ? 'yes' : 'no');
        $this->components->twoColumnDetail('Warnings', $this->formatWarnings($coverageTarget->warnings));
    }

    /**
     * Exact, float-free cents formatter. Sign is handled as a string prefix
     * (never abs()) so PHP_INT_MIN — whose magnitude has no positive int
     * representation — never triggers an implicit float conversion.
     */
    private function formatMoney(int $cents): string
    {
        $negative = $cents < 0;
        $digits = $negative ? substr((string) $cents, 1) : (string) $cents;
        $digits = str_pad($digits, 3, '0', STR_PAD_LEFT);

        $whole = substr($digits, 0, -2);
        $fraction = substr($digits, -2);

        return sprintf('%s%s.%s (%d cents)', $negative ? '-' : '', $whole, $fraction, $cents);
    }

    /**
     * Stable presentation for a legitimately absent domain result (e.g. no
     * positive-weight position observed) — never a fabricated 0.00 amount.
     */
    private function formatOptionalMoney(?Money $money): string
    {
        return $money === null ? 'N/A' : $this->formatMoney($money->cents());
    }

    /**
     * Exact, float-free percentage formatter. Percentage is parts-per-billion
     * (whole = 1_000_000_000 ppb); the last 7 digits are the percent's
     * decimal fraction (1 percent = 10_000_000 ppb). Only trailing fractional
     * zeros are trimmed, and the decimal point is omitted entirely once the
     * fraction is empty.
     */
    private function formatPercentage(int $partsPerBillion): string
    {
        $negative = $partsPerBillion < 0;
        $digits = $negative ? substr((string) $partsPerBillion, 1) : (string) $partsPerBillion;
        $digits = str_pad($digits, 8, '0', STR_PAD_LEFT);

        $whole = substr($digits, 0, -7);
        $fraction = rtrim(substr($digits, -7), '0');

        return $fraction === ''
            ? sprintf('%s%s%%', $negative ? '-' : '', $whole)
            : sprintf('%s%s.%s%%', $negative ? '-' : '', $whole, $fraction);
    }

    /**
     * Stable presentation for a legitimately absent domain result — never a
     * fabricated 0% or invented "impossible" state.
     */
    private function formatOptionalPercentage(?Percentage $percentage): string
    {
        return $percentage === null ? 'N/A' : $this->formatPercentage($percentage->partsPerBillion());
    }

    /**
     * Deterministic, unfiltered rendering of the domain warning list using
     * each enum case's ->value (never the PHP case name), in the canonical
     * order CopyCoverageCalculator already guarantees.
     *
     * @param  list<CoverageWarning>  $warnings
     */
    private function formatWarnings(array $warnings): string
    {
        if ($warnings === []) {
            return 'none';
        }

        return implode(', ', array_map(fn (CoverageWarning $warning): string => $warning->value, $warnings));
    }
}
