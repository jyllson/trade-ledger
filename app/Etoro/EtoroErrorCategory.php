<?php

namespace App\Etoro;

enum EtoroErrorCategory: string
{
    case Validation = 'validation';
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case NotFound = 'not_found';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    case ConnectionFailed = 'connection_failed';
}
