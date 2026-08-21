<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportRuns\Pages;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use Filament\Resources\Pages\ListRecords;

class ListImportRuns extends ListRecords
{
    protected static string $resource = ImportRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
