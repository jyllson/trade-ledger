<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use InvalidArgumentException;

/**
 * A single eToro public trader profile. Deliberately carries only gcid,
 * username, isPopularInvestor (observed isPi), isVerified, countryCode, and
 * languageIsoCode — none of the ~24 remaining observed profile fields
 * (firstName/lastName, aboutMe/aboutMeShort, userBio, avatars, homepage,
 * userFlowSignature, realCID/demoCID, ...) have a confirmed
 * semantics/consumer in this milestone (see docs/DECISIONS.md).
 */
final readonly class TraderProfile
{
    public string $username;

    public string $languageIsoCode;

    /**
     * $gcid is received already normalized (mirroring App\Etoro\Mappers\
     * Support\Identifiers::normalize()'s contract) — it is validated but
     * never itself trimmed or otherwise rewritten here, so a value that
     * merely looks numeric (e.g. "0", "-1") is never rejected on that
     * basis alone.
     */
    public function __construct(
        public string $gcid,
        string $username,
        public bool $isPopularInvestor,
        public bool $isVerified,
        public int $countryCode,
        string $languageIsoCode,
    ) {
        if (str_contains($gcid, "\0")) {
            throw new InvalidArgumentException('TraderProfile gcid must not contain a NUL byte.');
        }

        if (trim($gcid, " \t\n\r\v\f") === '') {
            throw new InvalidArgumentException('TraderProfile gcid must not be blank.');
        }

        $this->username = self::assertTrimmed($username, 'username');
        $this->languageIsoCode = self::assertTrimmed($languageIsoCode, 'languageIsoCode');
    }

    private static function assertTrimmed(string $raw, string $propertyName): string
    {
        if (str_contains($raw, "\0")) {
            throw new InvalidArgumentException("TraderProfile {$propertyName} must not contain a NUL byte.");
        }

        // Whitespace-only charlist, not trim()'s default charlist, which
        // also strips "\0" and would let a NUL-only string slip past the
        // check above undetected.
        $trimmed = trim($raw, " \t\n\r\v\f");

        if ($trimmed === '') {
            throw new InvalidArgumentException("TraderProfile {$propertyName} must not be blank.");
        }

        return $trimmed;
    }
}
