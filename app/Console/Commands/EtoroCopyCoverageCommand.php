<?php

namespace App\Console\Commands;

use App\Analytics\Exceptions\CoverageCalculationException;
use App\Analytics\ValueObjects\Money;
use App\Application\Etoro\EvaluateTraderCopyCoverage;
use App\Application\Etoro\EvaluateTraderCopyCoverageResult;
use App\Etoro\Exceptions\EtoroConfigurationException;
use App\Etoro\Exceptions\EtoroMappingException;
use App\Etoro\Exceptions\EtoroRequestException;
use App\Etoro\Exceptions\EtoroUnexpectedResponseException;
use Illuminate\Console\Command;

final class EtoroCopyCoverageCommand extends Command
{
    protected $signature = 'etoro:copy-coverage
        {trader-username : eToro trader username to evaluate}
        {copy-amount-cents : Total copy amount in integer cents}
        {minimum-position-cents : Minimum copied-position amount in integer cents}';

    protected $description = "Evaluate a trader's live-portfolio copy coverage for a given copy amount. Read-only, never writes or trades.";

    public function __construct(private readonly EvaluateTraderCopyCoverage $useCase)
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

        $copyAmountCents = $this->parseCents((string) $this->argument('copy-amount-cents'), 'copy-amount-cents');

        if ($copyAmountCents === null) {
            return self::FAILURE;
        }

        if ($copyAmountCents < 0) {
            $this->components->error('copy-amount-cents must not be negative.');

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

        $copyAmount = Money::fromCents($copyAmountCents);
        $minimumPositionAmount = Money::fromCents($minimumPositionCents);

        try {
            $result = $this->useCase->handle($traderUsername, $copyAmount, $minimumPositionAmount);
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

        $this->renderSummary($result, $copyAmountCents, $minimumPositionCents);

        return self::SUCCESS;
    }

    /**
     * Accepts only `^-?\d+$` syntax, then confirms the value is representable
     * as a PHP int via exact BCMath bounds comparison before ever casting —
     * an overflow here must fail loudly, never silently wrap or truncate.
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

    private function renderSummary(EvaluateTraderCopyCoverageResult $result, int $copyAmountCents, int $minimumPositionCents): void
    {
        $this->components->info('eToro copy coverage');

        $this->components->twoColumnDetail('Trader username', $result->traderUsername);
        $this->components->twoColumnDetail('Request ID', $result->requestId);
        $this->components->twoColumnDetail('Copy amount', $this->formatMoney($copyAmountCents));
        $this->components->twoColumnDetail('Minimum position', $this->formatMoney($minimumPositionCents));
        $this->components->twoColumnDetail('Eligible positions', (string) $result->coverage->eligibleCount);
        $this->components->twoColumnDetail('Skipped positions', (string) $result->coverage->skippedCount);
        $this->components->twoColumnDetail('Covered weight', $this->formatPercentage($result->coverage->coveredWeight->partsPerBillion()));

        if ($result->coverage->warnings !== []) {
            $this->newLine();
            $this->components->warn('Data quality warnings');

            foreach ($result->coverage->warnings as $warning) {
                $this->line('  - '.$warning->value);
            }
        }
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
}
