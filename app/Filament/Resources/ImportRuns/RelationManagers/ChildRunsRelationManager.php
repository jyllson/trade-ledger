<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportRuns\RelationManagers;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Direct per-page `rankings` child runs of a `rankings_discovery`
 * aggregate run — read-only, applicable only to aggregate owner records.
 */
class ChildRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'childRuns';

    protected static ?string $title = 'Child runs';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof ImportRun && $ownerRecord->type === 'rankings_discovery';
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('id')
                    ->label('Run'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ImportRunStatus $state): string => ucfirst($state->value))
                    ->color(fn (ImportRunStatus $state): string => match ($state) {
                        ImportRunStatus::Pending => 'gray',
                        ImportRunStatus::Running => 'info',
                        ImportRunStatus::Completed => 'success',
                        ImportRunStatus::Partial => 'warning',
                        ImportRunStatus::Failed => 'danger',
                    }),
                TextColumn::make('success_count')
                    ->label('Succeeded'),
                TextColumn::make('failure_count')
                    ->label('Rejected'),
                TextColumn::make('started_at')
                    ->dateTime(),
                TextColumn::make('finished_at')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->defaultSort('id')
            ->recordActions([
                ViewAction::make()
                    ->url(fn (ImportRun $record): string => ImportRunResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('No child runs')
            ->emptyStateDescription('No per-page ranking runs have been recorded under this discovery run yet.');
    }
}
