<?php

namespace App\Etoro;

/**
 * Classification recorded in docs/ETORO_API_CAPABILITIES.md for each probed
 * capability, including the four-way real/demo P&L classification (mapped as
 * available=Works, forbidden=RequiresAdditionalScope (P&L is account-level,
 * same as `me`/rankings — a 403 there means insufficient scope, not a
 * visibility-restricted trader),
 * unavailable=NotAvailable|TemporarilyUnavailable,
 * authentication_blocked=AuthenticationBlocked).
 */
enum CapabilityStatus: string
{
    case Works = 'works';
    case WorksWithPartialData = 'works_with_partial_data';
    case RequiresAdditionalScope = 'requires_additional_scope';
    case AuthenticationBlocked = 'authentication_blocked';
    case PrivateOrVisibilityDependent = 'private_or_visibility_dependent';
    case NotAvailable = 'not_available';
    case UnexpectedSchema = 'unexpected_schema';
    case TemporarilyUnavailable = 'temporarily_unavailable';
    case Skipped = 'skipped';
}
