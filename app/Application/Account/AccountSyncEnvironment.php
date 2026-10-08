<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Etoro\EtoroEnvironment;

/**
 * Which eToro account environments may be synced (docs/DECISIONS.md D-051).
 * PROJECT.md §20 M6: "Real account read access is enabled only after Demo
 * acceptance" — so only Demo is enabled, in code, with no config flag that
 * could open Real by accident. Enabling Real is a reviewed code change
 * after the owner accepts Demo.
 */
final class AccountSyncEnvironment
{
    /** @var list<EtoroEnvironment> */
    public const ENABLED = [EtoroEnvironment::Demo];

    private function __construct() {}

    public static function isEnabled(EtoroEnvironment $environment): bool
    {
        return in_array($environment, self::ENABLED, true);
    }

    /**
     * @throws AccountSyncNotEnabled
     */
    public static function assertEnabled(EtoroEnvironment $environment): void
    {
        if (! self::isEnabled($environment)) {
            throw AccountSyncNotEnabled::for($environment);
        }
    }
}
