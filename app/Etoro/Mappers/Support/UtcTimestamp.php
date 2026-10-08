<?php

declare(strict_types=1);

namespace App\Etoro\Mappers\Support;

use App\Etoro\Exceptions\EtoroMappingException;
use DateTimeImmutable;
use DateTimeZone;
use ValueError;

/**
 * Strict parser for eToro UTC timestamps — the only formats observed live
 * (docs/DECISIONS.md D-044): whole seconds ("2012-01-10T09:15:00Z") and a
 * fractional second of 1–7 digits ("2012-01-10T09:15:00.12Z"). Always UTC
 * `Z`. A future format needs an explicit, reviewed extension, not silent
 * tolerance.
 */
final class UtcTimestamp
{
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s\Z';

    private const FRACTIONAL_TIMESTAMP_PATTERN = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})\.(\d{1,7})Z$/D';

    private const FRACTIONAL_TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    private function __construct() {}

    public static function parse(string $raw, string $mapper, string $fieldPath): DateTimeImmutable
    {
        [$candidate, $format] = self::normalized($raw);

        try {
            $parsed = DateTimeImmutable::createFromFormat($format, $candidate, new DateTimeZone('UTC'));
        } catch (ValueError) {
            // createFromFormat() throws ValueError when the decoded string
            // contains a null byte, for example from a JSON Unicode null escape.
            // Never expose the original ValueError message because it may contain
            // source data.
            throw EtoroMappingException::malformedTimestamp($mapper, $fieldPath);
        }

        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw EtoroMappingException::malformedTimestamp($mapper, $fieldPath);
        }

        // createFromFormat can silently accept a lenient variant (e.g. an
        // unpadded month/day, or a date that rolled over) without raising a
        // warning/error. Re-formatting and comparing against the original
        // string is the only way to catch that.
        if ($parsed->format($format) !== $candidate) {
            throw EtoroMappingException::malformedTimestamp($mapper, $fieldPath);
        }

        return $parsed;
    }

    /**
     * A fractional second is padded/truncated to the 6 digits PHP's `u`
     * accepts (a 7th, sub-microsecond digit is dropped), so the strict
     * re-format comparison still applies. Anything else is parsed as-is
     * against the whole-second format.
     *
     * @return array{string, string} the string to parse and its format
     */
    private static function normalized(string $raw): array
    {
        if (preg_match(self::FRACTIONAL_TIMESTAMP_PATTERN, $raw, $matches) === 1) {
            $microseconds = substr(str_pad($matches[2], 6, '0'), 0, 6);

            return ["{$matches[1]}.{$microseconds}Z", self::FRACTIONAL_TIMESTAMP_FORMAT];
        }

        return [$raw, self::TIMESTAMP_FORMAT];
    }
}
