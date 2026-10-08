<?php

declare(strict_types=1);

namespace App\Application\DemoCopy;

use App\Etoro\EtoroWriteGuard;

/**
 * Whether demo copy trading may be used right now (D-050): the eToro
 * integration is enabled AND ETORO_ALLOW_DEMO_COPY is strictly true. The UI
 * asks this to show or disable its actions; every use case asks again
 * before acting, and EtoroWriteGuard checks once more per HTTP request.
 */
final class DemoCopyAvailability
{
    public function __construct(private readonly EtoroWriteGuard $guard) {}

    public function isEnabled(): bool
    {
        return $this->disabledReason() === null;
    }

    public function disabledReason(): ?string
    {
        if (! $this->guard->allowsDemoCopy()) {
            return 'Demo copy trading is disabled (ETORO_ALLOW_DEMO_COPY is not true). The application stays read-only.';
        }

        if (! config('etoro.enabled')) {
            return 'The eToro integration is disabled (ETORO_ENABLED is not true).';
        }

        return null;
    }

    /**
     * @throws DemoCopyRefused
     */
    public function ensureEnabled(): void
    {
        $reason = $this->disabledReason();

        if ($reason !== null) {
            throw DemoCopyRefused::disabled($reason);
        }
    }
}
