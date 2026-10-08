<?php

declare(strict_types=1);

namespace App\Application\Account;

enum SyncEtoroAccountStopReason: string
{
    case Completed = 'completed';
    case ConfigurationError = 'configuration_error';
    /** 401/403 — the key cannot read this account (missing permission). */
    case NotAuthorized = 'not_authorized';
    /** 429, 5xx after bounded retries, or a transport failure — safe to retry later. */
    case TemporarilyUnavailable = 'temporarily_unavailable';
    case RequestFailed = 'request_failed';
    case UnexpectedResponse = 'unexpected_response';
    case MappingFailed = 'mapping_failed';
    case UnexpectedFailure = 'unexpected_failure';

    public function isRetryable(): bool
    {
        return $this === self::TemporarilyUnavailable;
    }
}
