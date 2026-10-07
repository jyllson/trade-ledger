<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalysisProfiles\Pages;

use App\Filament\Resources\AnalysisProfiles\AnalysisProfileResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * A new profile is never the default; "Make default" moves the flag.
 */
class CreateAnalysisProfile extends CreateRecord
{
    protected static string $resource = AnalysisProfileResource::class;
}
