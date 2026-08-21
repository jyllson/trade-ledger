<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportRuns\Tables;

use App\Application\Imports\ImportRunNotRetryableException;
use App\Application\Imports\RetryEtoroTraderDiscovery;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

/**
 * Read-only import run audit table. The only write this table ever
 * triggers is a manual retry, and only through
 * App\Application\Imports\RetryEtoroTraderDiscovery — both its
 * visibility (canRetry()) and its execution (handle()) always go through
 * that one application service, never a locally duplicated eligibility
 * check.
 */
class ImportRunsTable
{
    /**
     * @var array<string, string>
     */
    private const TYPE_LABELS = [
        'rankings_discovery' => 'Discovery (aggregate)',
        'rankings' => 'Ranking page',
        'profile' => 'Profile lookup',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Run')
                    ->sortable(),
                TextColumn::make('source')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('type')
                    ->formatStateUsing(fn (string $state): string => self::TYPE_LABELS[$state] ?? $state)
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ImportRunStatus $state): string => self::statusLabel($state))
                    ->color(fn (ImportRunStatus $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('request_count')
                    ->label('Requests')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('success_count')
                    ->label('Succeeded')
                    ->numeric(),
                TextColumn::make('failure_count')
                    ->label('Rejected')
                    ->numeric(),
                TextColumn::make('parent_import_run_id')
                    ->label('Parent run')
                    ->placeholder('—')
                    ->url(fn (ImportRun $record): ?string => $record->parent_import_run_id === null
                        ? null
                        : ImportRunResource::getUrl('view', ['record' => $record->parent_import_run_id])),
                TextColumn::make('retry_of_import_run_id')
                    ->label('Retry of run')
                    ->placeholder('—')
                    ->url(fn (ImportRun $record): ?string => $record->retry_of_import_run_id === null
                        ? null
                        : ImportRunResource::getUrl('view', ['record' => $record->retry_of_import_run_id])),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        ImportRunStatus::Pending->value => self::statusLabel(ImportRunStatus::Pending),
                        ImportRunStatus::Running->value => self::statusLabel(ImportRunStatus::Running),
                        ImportRunStatus::Completed->value => self::statusLabel(ImportRunStatus::Completed),
                        ImportRunStatus::Partial->value => self::statusLabel(ImportRunStatus::Partial),
                        ImportRunStatus::Failed->value => self::statusLabel(ImportRunStatus::Failed),
                    ]),
                SelectFilter::make('type')
                    ->options(self::TYPE_LABELS),
            ])
            ->recordActions([
                ViewAction::make(),
                self::retryAction(),
            ])
            ->emptyStateHeading('No import runs yet')
            ->emptyStateDescription('Ranking discovery, ranking page imports, and profile lookups will appear here as audit records.')
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList);
    }

    private static function retryAction(): Action
    {
        return Action::make('retry')
            ->label('Retry')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (ImportRun $record): bool => app(RetryEtoroTraderDiscovery::class)->canRetry($record))
            ->requiresConfirmation()
            ->modalHeading('Retry this discovery run?')
            ->modalDescription(fn (ImportRun $record): string => self::retryModalDescription($record))
            ->action(function (ImportRun $record): void {
                try {
                    $result = app(RetryEtoroTraderDiscovery::class)->handle($record);
                } catch (ImportRunNotRetryableException) {
                    Notification::make()
                        ->title('This run is no longer eligible for retry')
                        ->warning()
                        ->send();

                    return;
                } catch (Throwable) {
                    Notification::make()
                        ->title('Retry could not be completed')
                        ->body('An unexpected error occurred while completing this operation. Please try again shortly.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("Retry started — run #{$result->importRun->id}")
                    ->body('Status: '.self::statusLabel($result->importRun->status))
                    ->success()
                    ->actions([
                        Action::make('viewRun')
                            ->label('View retry run')
                            ->url(ImportRunResource::getUrl('view', ['record' => $result->importRun->id])),
                    ])
                    ->send();
            });
    }

    /**
     * Sanitized facts only — the same period/start_page/max_pages/sort/
     * country query values already stored in this run's own metadata,
     * never a raw exception message, request ID, or credential.
     */
    private static function retryModalDescription(ImportRun $record): string
    {
        $query = is_array($record->metadata) ? ($record->metadata['query'] ?? []) : [];

        $period = is_array($query) ? ($query['period'] ?? '—') : '—';
        $startPage = is_array($query) ? ($query['start_page'] ?? '—') : '—';
        $maxPages = is_array($query) ? ($query['max_pages'] ?? '—') : '—';

        return "This starts a new, read-only eToro ranking discovery run using the same query as run #{$record->id}: period {$period}, starting at page {$startPage}, up to {$maxPages} page(s).";
    }

    private static function statusLabel(ImportRunStatus $status): string
    {
        return match ($status) {
            ImportRunStatus::Pending => 'Pending',
            ImportRunStatus::Running => 'Running',
            ImportRunStatus::Completed => 'Completed',
            ImportRunStatus::Partial => 'Partial',
            ImportRunStatus::Failed => 'Failed',
        };
    }

    private static function statusColor(ImportRunStatus $status): string
    {
        return match ($status) {
            ImportRunStatus::Pending => 'gray',
            ImportRunStatus::Running => 'info',
            ImportRunStatus::Completed => 'success',
            ImportRunStatus::Partial => 'warning',
            ImportRunStatus::Failed => 'danger',
        };
    }
}
