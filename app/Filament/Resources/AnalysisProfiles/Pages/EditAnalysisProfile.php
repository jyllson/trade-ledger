<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalysisProfiles\Pages;

use App\Filament\Resources\AnalysisProfiles\AnalysisProfileResource;
use App\Filament\Resources\AnalysisProfiles\Tables\AnalysisProfilesTable;
use Filament\Resources\Pages\EditRecord;

class EditAnalysisProfile extends EditRecord
{
    protected static string $resource = AnalysisProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AnalysisProfilesTable::makeDefaultAction(),
            AnalysisProfilesTable::deleteAction(),
        ];
    }
}
