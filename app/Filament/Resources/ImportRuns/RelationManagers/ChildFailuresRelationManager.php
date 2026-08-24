<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportRuns\RelationManagers;

use App\Models\ImportRun;
use App\Models\ImportRunFailureReason;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Every rejected row across a `rankings_discovery` aggregate's direct
 * child runs, in one place — see App\Models\ImportRun::childFailures().
 * Read-only, applicable only to an aggregate owner record.
 */
class ChildFailuresRelationManager extends RelationManager
{
    protected static string $relationship = 'childFailures';

    protected static ?string $title = 'Rejected rows (all pages)';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof ImportRun && $ownerRecord->type === 'rankings_discovery';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('row_number')
            ->columns([
                TextColumn::make('import_run_id')
                    ->label('Page run'),
                TextColumn::make('row_number')
                    ->label('Row #'),
                TextColumn::make('reason')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (ImportRunFailureReason $state): string => match ($state) {
                        ImportRunFailureReason::IdentityConflictWithinPage => 'Conflict within page',
                        ImportRunFailureReason::IdentityConflictWithExistingTrader => 'Conflict with existing trader',
                    }),
                TextColumn::make('external_cid')
                    ->label('eToro CID'),
                TextColumn::make('username'),
                TextColumn::make('created_at')
                    ->label('Recorded at')
                    ->dateTime(),
            ])
            ->defaultSort('row_number')
            ->recordActions([])
            ->emptyStateHeading('No rejected rows')
            ->emptyStateDescription('No controlled identity conflicts were recorded across this discovery run\'s pages.');
    }
}
