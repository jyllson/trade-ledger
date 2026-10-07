<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalysisProfiles\Pages;

use App\Filament\Resources\AnalysisProfiles\AnalysisProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Read-only on GET (D-048): the default profile is stored by a migration,
 * never by visiting the list.
 */
class ListAnalysisProfiles extends ListRecords
{
    protected static string $resource = AnalysisProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
