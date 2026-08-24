<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportRuns;

use App\Filament\Resources\ImportRuns\Pages\ListImportRuns;
use App\Filament\Resources\ImportRuns\Pages\ViewImportRun;
use App\Filament\Resources\ImportRuns\RelationManagers\ChildFailuresRelationManager;
use App\Filament\Resources\ImportRuns\RelationManagers\ChildRunsRelationManager;
use App\Filament\Resources\ImportRuns\RelationManagers\FailuresRelationManager;
use App\Filament\Resources\ImportRuns\RelationManagers\RetryAttemptsRelationManager;
use App\Filament\Resources\ImportRuns\Schemas\ImportRunInfolist;
use App\Filament\Resources\ImportRuns\Tables\ImportRunsTable;
use App\Models\ImportRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only audit trail for every import run (live ranking discovery,
 * per-page ranking imports, and profile lookups). List and View pages
 * only — no Create/Edit/Delete routes exist at all. The only write this
 * resource ever performs is a manual retry, which goes through
 * App\Application\Imports\RetryEtoroTraderDiscovery — see
 * Tables\ImportRunsTable.
 */
class ImportRunResource extends Resource
{
    protected static ?string $model = ImportRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Imports';

    protected static string|UnitEnum|null $navigationGroup = 'Research';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return ImportRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ImportRunsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ChildRunsRelationManager::class,
            FailuresRelationManager::class,
            ChildFailuresRelationManager::class,
            RetryAttemptsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportRuns::route('/'),
            'view' => ViewImportRun::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
