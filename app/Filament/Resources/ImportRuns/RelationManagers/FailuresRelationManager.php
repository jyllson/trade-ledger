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
 * Row-level controlled identity conflicts rejected directly under THIS
 * run — read-only, applicable only to a per-page `rankings` (or
 * fixture-only) owner record. A `rankings_discovery` aggregate never
 * accumulates rows here directly — see ChildFailuresRelationManager.
 */
class FailuresRelationManager extends RelationManager
{
    protected static string $relationship = 'failures';

    protected static ?string $title = 'Rejected rows';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof ImportRun && $ownerRecord->type === 'rankings';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('row_number')
            ->columns([
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
            ->emptyStateDescription('No controlled identity conflicts were recorded for this run.');
    }
}
