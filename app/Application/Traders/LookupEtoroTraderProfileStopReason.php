<?php

declare(strict_types=1);

namespace App\Application\Traders;

enum LookupEtoroTraderProfileStopReason: string
{
    case Completed = 'completed';
    case ConfigurationError = 'configuration_error';
    case RequestFailed = 'request_failed';
    case UnexpectedResponse = 'unexpected_response';
    case MappingFailed = 'mapping_failed';
    case ProfileIdentityMismatch = 'profile_identity_mismatch';
    case UnexpectedFailure = 'unexpected_failure';
}
