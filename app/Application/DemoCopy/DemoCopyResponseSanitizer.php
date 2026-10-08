<?php

declare(strict_types=1);

namespace App\Application\DemoCopy;

use App\Models\DemoCopyOperationType;

/**
 * Keeps only documented, allow-listed scalar fields of a demo copy
 * response for the audit log (D-050) — e.g. the pre-check's own-account
 * `CID` is dropped. Strings lose control characters, have every
 * configured eToro credential value redacted (also in JSON-escaped and
 * URL-encoded form) and are truncated without leaving a key prefix behind.
 */
final class DemoCopyResponseSanitizer
{
    private const int MAX_FIELD_LENGTH = 255;

    private const int MAX_REASON_LENGTH = 500;

    /**
     * Shorter configured values are not redacted, so an empty or placeholder
     * secret cannot blank out ordinary text.
     */
    private const int MIN_SECRET_LENGTH = 8;

    private const string REDACTED = '[redacted]';

    /**
     * Every credential in config/etoro.php.
     *
     * @var list<string>
     */
    private const SECRET_CONFIG_KEYS = ['etoro.api_key', 'etoro.user_key'];

    /**
     * Documented error bodies: CopyTradingErrorResponse (400/404/500) and
     * GatewayErrorResponse (401/403/429).
     *
     * @var list<string>
     */
    private const ERROR_FIELDS = ['error', 'errorCode', 'errorMessage'];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, bool|int|float|string>
     */
    public function payload(DemoCopyOperationType $type, array $payload): array
    {
        $fields = match ($type) {
            DemoCopyOperationType::PreCheck => ['parentCID', 'isSuccess', 'errorCode', 'errorMessage'],
            DemoCopyOperationType::Start, DemoCopyOperationType::Adjust, DemoCopyOperationType::Close => ['token'],
            DemoCopyOperationType::Poll => ['referenceID', 'isSuccess', 'mirrorID', 'parentCID', 'parentUsername', 'errorMessageCode', 'failReason'],
        };

        $sanitized = [];

        foreach (array_unique([...$fields, ...self::ERROR_FIELDS]) as $field) {
            $value = $payload[$field] ?? null;

            if (is_string($value)) {
                $sanitized[$field] = $this->truncate($this->scrub($value), self::MAX_FIELD_LENGTH);
            } elseif (is_bool($value) || is_int($value) || is_float($value)) {
                $sanitized[$field] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Human-readable error text from a documented error body, if any.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function errorMessage(array $payload): ?string
    {
        foreach (['error', 'errorMessage'] as $field) {
            if (is_string($payload[$field] ?? null) && trim($payload[$field]) !== '') {
                return $this->reason($payload[$field]);
            }
        }

        return null;
    }

    public function reason(string $reason): string
    {
        return $this->truncate($this->scrub($reason), self::MAX_REASON_LENGTH);
    }

    /**
     * Redacts before and after cleaning (a control character inside a
     * leaked secret must not let it through) and always before truncating.
     */
    private function scrub(string $value): string
    {
        return $this->redact($this->clean($this->redact($value)));
    }

    private function redact(string $value): string
    {
        $variants = $this->secretVariants();

        // Case-insensitive: percent-encoding and \u escapes may use either hex case.
        return $variants === [] ? $value : str_ireplace($variants, self::REDACTED, $value);
    }

    /**
     * Every configured secret (as configured and trimmed) in the forms an
     * upstream body or proxy can echo it: literal, JSON-escaped (every
     * combination of escaped slashes / unicode) and URL-encoded (`+` and `%20`
     * spaces). Base64 is left out: a secret's base64 form depends on its
     * byte alignment inside the encoded text, so no fixed string matches it.
     * Longest first, so one variant containing another is fully replaced.
     *
     * @return list<string>
     */
    private function secretVariants(): array
    {
        $variants = [];

        foreach (self::SECRET_CONFIG_KEYS as $key) {
            $secret = config($key);

            if (! is_string($secret) || mb_strlen(trim($secret)) < self::MIN_SECRET_LENGTH) {
                continue;
            }

            foreach (array_unique([$secret, trim($secret)]) as $value) {
                $variants[] = $value;
                $variants[] = urlencode($value);
                $variants[] = rawurlencode($value);

                foreach ([0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE] as $flags) {
                    $json = json_encode($value, $flags);

                    if (is_string($json)) {
                        $variants[] = substr($json, 1, -1);
                    }
                }
            }
        }

        $variants = array_values(array_unique(array_filter(
            $variants,
            static fn (string $variant): bool => strlen($variant) >= self::MIN_SECRET_LENGTH,
        )));

        usort($variants, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $variants;
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }

    /**
     * Truncates, then redacts a trailing prefix (≥ MIN_SECRET_LENGTH bytes)
     * of any secret variant — the cut, or an upstream that cut its own
     * text, must not leave the start of a key at the end.
     */
    private function truncate(string $value, int $length): string
    {
        $cut = mb_strlen($value) > $length;
        $body = $cut ? mb_substr($value, 0, $length - 1) : $value;
        $suffix = $cut ? '…' : '';

        while (($fragment = $this->trailingSecretPrefixLength($body)) > 0) {
            if (! str_starts_with($suffix, self::REDACTED)) {
                $suffix = self::REDACTED.$suffix;
            }

            $body = mb_substr($body, 0, min(mb_strlen($body) - $fragment, $length - mb_strlen($suffix)));
        }

        return $body.$suffix;
    }

    /**
     * Length in characters of the longest secret-variant prefix the value
     * ends with, or 0.
     */
    private function trailingSecretPrefixLength(string $value): int
    {
        $haystack = strtolower($value);

        foreach ($this->secretVariants() as $variant) {
            $needle = strtolower($variant);

            for ($bytes = min(strlen($needle), strlen($haystack)); $bytes >= self::MIN_SECRET_LENGTH; $bytes--) {
                if (str_ends_with($haystack, substr($needle, 0, $bytes))) {
                    return mb_strlen(substr($value, -$bytes));
                }
            }
        }

        return 0;
    }
}
