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
 * Runs that were created as an immediate manual retry of THIS run — see
 * App\Models\ImportRun::retryAttempts(). Read-only, applicable only to a
 * `rankings_discovery` owner record, since only discovery runs are ever
 * retryable.
 */
class RetryAttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'retryAttempts';

    protected static ?string $title = 'Retry attempts';

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
            ->emptyStateHeading('No retry attempts')
            ->emptyStateDescription('This run has not been retried yet.');
    }
}
