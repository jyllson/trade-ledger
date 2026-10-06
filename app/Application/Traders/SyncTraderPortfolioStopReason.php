<?php

declare(strict_types=1);

namespace App\Application\Traders;

enum SyncTraderPortfolioStopReason: string
{
    case Completed = 'completed';
    case ConfigurationError = 'configuration_error';
    /** 403 — trader opted out of portfolio exposure; a data state, not retried. */
    case NotVisible = 'not_visible';
    case NotFound = 'not_found';
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
