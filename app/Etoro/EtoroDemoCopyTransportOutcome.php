<?php

namespace App\Etoro;

/**
 * What is known about delivery of one demo copy-trading HTTP request.
 * The distinction matters for writes: only NotSent proves eToro never saw
 * the request; ConnectionFailed means it may or may not have been
 * processed.
 */
enum EtoroDemoCopyTransportOutcome: string
{
    /** eToro answered with an HTTP status (any status). */
    case Responded = 'responded';

    /** Refused by the local request budget (D-039); nothing was sent. */
    case NotSent = 'not_sent';

    /** Transport failure or timeout; delivery is unknown. */
    case ConnectionFailed = 'connection_failed';
}
