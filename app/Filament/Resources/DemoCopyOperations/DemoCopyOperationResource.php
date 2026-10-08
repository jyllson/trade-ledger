<?php

declare(strict_types=1);

namespace App\Filament\Resources\DemoCopyOperations;

use App\Filament\Resources\DemoCopyOperations\Pages\ListDemoCopyOperations;
use App\Filament\Resources\DemoCopyOperations\Tables\DemoCopyOperationsTable;
use App\Models\DemoCopyOperation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only audit log of every demo copy-trading attempt (D-050). List
 * page only — no Create/Edit/Delete routes. The only action it offers is
 * „Check outcome“: one status poll through PollDemoCopyOutcome.
 */
class DemoCopyOperationResource extends Resource
{
    protected static ?string $model = DemoCopyOperation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Demo copy operations';

    protected static ?string $modelLabel = 'demo copy operation';

    protected static string|UnitEnum|null $navigationGroup = 'Demo trading';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return DemoCopyOperationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDemoCopyOperations::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
