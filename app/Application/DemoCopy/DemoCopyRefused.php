<?php

declare(strict_types=1);

namespace App\Application\DemoCopy;

use RuntimeException;

/**
 * A demo copy use case refused to act before anything was sent to eToro
 * (D-050). The message is safe to show in the UI.
 */
final class DemoCopyRefused extends RuntimeException
{
    public const string CLOSE_UNCONFIRMED_START = 'A close of this trader\'s demo copy was requested but its completion is NOT confirmed. If it did not complete, eToro may treat a new start as adding funds to the existing copy. Verify in eToro that the copy is closed, then explicitly acknowledge it.';

    public const string CLOSE_UNCONFIRMED_ADJUST = 'A close of this demo copy was requested and its completion is not confirmed — adjusting is not available. Verify the copy in eToro.';

    public static function disabled(string $reason): self
    {
        return new self($reason);
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
