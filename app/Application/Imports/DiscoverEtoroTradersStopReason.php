<?php

declare(strict_types=1);

namespace App\Application\Imports;

enum DiscoverEtoroTradersStopReason: string
{
    case NaturalCompletion = 'natural_completion';
    case PageLimitReached = 'page_limit_reached';
    case PaginationMismatch = 'pagination_mismatch';
    case ConfigurationError = 'configuration_error';
    case RequestFailed = 'request_failed';
    case UnexpectedResponse = 'unexpected_response';
    case MappingFailed = 'mapping_failed';
    case UnexpectedFailure = 'unexpected_failure';
}
