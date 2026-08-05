<?php

declare(strict_types=1);

namespace App\Etoro\Data;

use InvalidArgumentException;

/**
 * A single eToro investor-ranking row. Deliberately carries only cid,
 * username, type, subType, and copiers — none of the ~48 remaining
 * observed rankings fields (gain, avatarUrl, fullName, risk/AUM metrics,
 * tags, timestamps, ...) have a confirmed semantics/consumer in this
 * milestone (see docs/DECISIONS.md).
 */
final readonly class RankingEntry
{
    public string $username;

    public string $type;

    public string $subType;

    /**
     * $cid is received already normalized (mirroring App\Etoro\Mappers\
     * Support\Identifiers::normalize()'s contract) — it is validated but
     * never itself trimmed or otherwise rewritten here, so a value that
     * merely looks numeric (e.g. "0", "-1") is never rejected on that
     * basis alone.
     */
    public function __construct(
        public string $cid,
        string $username,
        string $type,
        string $subType,
        public int $copiers,
    ) {
        if (str_contains($cid, "\0")) {
            throw new InvalidArgumentException('RankingEntry cid must not contain a NUL byte.');
        }

        if (trim($cid, " \t\n\r\v\f") === '') {
            throw new InvalidArgumentException('RankingEntry cid must not be blank.');
        }

        $this->username = self::assertTrimmed($username, 'username');
        $this->type = self::assertTrimmed($type, 'type');
        $this->subType = self::assertTrimmed($subType, 'subType');

        if ($copiers < 0) {
            throw new InvalidArgumentException('RankingEntry copiers must not be negative.');
        }
    }

    private static function assertTrimmed(string $raw, string $propertyName): string
    {
        if (str_contains($raw, "\0")) {
            throw new InvalidArgumentException("RankingEntry {$propertyName} must not contain a NUL byte.");
        }

        // Whitespace-only charlist, not trim()'s default charlist, which
        // also strips "\0" and would let a NUL-only string slip past the
        // check above undetected.
        $trimmed = trim($raw, " \t\n\r\v\f");

        if ($trimmed === '') {
            throw new InvalidArgumentException("RankingEntry {$propertyName} must not be blank.");
        }

        return $trimmed;
    }
}
