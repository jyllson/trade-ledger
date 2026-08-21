<?php

declare(strict_types=1);

namespace App\Etoro\FixtureSources;

enum RankingFixtureFailureReason: string
{
    case SourceUnavailable = 'source_unavailable';
    case InvalidJson = 'invalid_json';
    case UnexpectedTopLevelShape = 'unexpected_top_level_shape';
    case PaginationMismatch = 'pagination_mismatch';
}
