<?php

declare(strict_types=1);

namespace App\Etoro\Exceptions;

enum EtoroMappingErrorReason: string
{
    case MissingRequiredField = 'missing_required_field';
    case InvalidPrimitiveType = 'invalid_primitive_type';
    case InvalidValue = 'invalid_value';
    case MalformedTimestamp = 'malformed_timestamp';
    case UnexpectedShape = 'unexpected_shape';
}
