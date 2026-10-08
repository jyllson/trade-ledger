<?php

declare(strict_types=1);

namespace App\Application\DemoCopy;

/**
 * Human-readable reason for an eToro demo copy refusal (pre-check
 * `isSuccess=false`, or a failed status poll), built only from what eToro
 * returned (docs/DECISIONS.md D-050, D-051):
 *
 * - message (+ code when present) → the message, then the code;
 * - code without a message → the code itself, plus — only for codes in
 *   OBSERVED_INTERPRETATIONS — a clearly labelled interpretation that did
 *   NOT come from the API;
 * - neither → "no reason given".
 *
 * eToro documents these codes only as "opaque diagnostic data", so the fact
 * (the code) is always kept separate from any interpretation.
 */
final class DemoCopyErrorReason
{
    /**
     * Undocumented codes observed live, with where the interpretation comes
     * from. Never presented as an API statement.
     */
    public const OBSERVED_INTERPRETATIONS = [
        972 => "eToro's web app reports this account does not meet the minimum deposit requirement for copy trading (interpretation from the eToro web app, observed 2026-10-08 — not returned by the API).",
    ];

    private function __construct() {}

    /**
     * @param  string  $headline  e.g. "eToro refused the copy"
     * @param  string  $codeField  the documented field name, e.g. `errorCode`
     */
    public static function describe(string $headline, string $codeField, mixed $code, mixed $message): string
    {
        $code = is_int($code) ? $code : null;
        $message = is_string($message) && trim($message) !== '' ? trim($message) : null;
        $codeLabel = $code === null ? null : "{$codeField} {$code}";

        return match (true) {
            $message !== null => "{$headline} — {$message}".($codeLabel === null ? '.' : " ({$codeLabel})."),
            $codeLabel !== null => "{$headline} ({$codeLabel}). ".(self::OBSERVED_INTERPRETATIONS[$code] ?? 'eToro gave no message, and the code is not documented.'),
            default => "{$headline} (no reason given).",
        };
    }
}
