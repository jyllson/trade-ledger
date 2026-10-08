<?php

declare(strict_types=1);

namespace App\Filament\Resources\DemoCopyOperations\Pages;

use App\Filament\Resources\DemoCopyOperations\DemoCopyOperationResource;
use Filament\Resources\Pages\ListRecords;

class ListDemoCopyOperations extends ListRecords
{
    protected static string $resource = DemoCopyOperationResource::class;

    protected ?string $subheading = 'Every demo copy attempt (DEMO account, virtual money): pre-checks, start/adjust/close requests and outcome polls. Keys and headers are never stored.';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
