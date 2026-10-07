<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalysisProfiles\Tables;

use App\Application\AnalysisProfiles\AnalysisProfileInput;
use App\Application\AnalysisProfiles\DefaultAnalysisProfileCannotBeDeleted;
use App\Application\AnalysisProfiles\DeleteAnalysisProfiles;
use App\Application\AnalysisProfiles\MakeAnalysisProfileDefault;
use App\Models\AnalysisProfile;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * Analysis profiles (D-048). The default profile can never be deleted:
 * every delete (single and bulk) goes through DeleteAnalysisProfiles,
 * which re-checks the current default under lock; the default flag moves
 * only through "Make default" (MakeAnalysisProfileDefault, atomic).
 */
class AnalysisProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                TextColumn::make('budget_cents')
                    ->label('Budget')
                    ->formatStateUsing(fn (int $state): string => '$'.AnalysisProfileInput::formatUsd($state))
                    ->sortable(),
                TextColumn::make('target_coverage_ppb')
                    ->label('Target coverage')
                    ->formatStateUsing(fn (int $state): string => AnalysisProfileInput::formatPercent($state).'%'),
                TextColumn::make('maximum_drawdown_ppb')
                    ->label('Max drawdown')
                    ->formatStateUsing(fn (int $state): string => AnalysisProfileInput::formatPercent($state).'%')
                    ->placeholder('Not applied'),
                TextColumn::make('maximum_single_position_ppb')
                    ->label('Max single position')
                    ->formatStateUsing(fn (int $state): string => AnalysisProfileInput::formatPercent($state).'%')
                    ->placeholder('Not applied'),
                TextColumn::make('minimum_history_months')
                    ->label('Min history')
                    ->suffix(' months')
                    ->placeholder('Not applied'),
                TextColumn::make('minimum_positive_months_ppb')
                    ->label('Min positive months')
                    ->formatStateUsing(fn (int $state): string => AnalysisProfileInput::formatPercent($state).'%')
                    ->placeholder('Not applied'),
                TextColumn::make('maximum_risk_score')
                    ->label('Max risk score')
                    ->placeholder('Not applied')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('maximum_allocation_per_trader_ppb')
                    ->label('Max allocation per trader')
                    ->formatStateUsing(fn (int $state): string => AnalysisProfileInput::formatPercent($state).'%')
                    ->placeholder('Not applied')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                self::makeDefaultAction(),
                EditAction::make(),
                self::deleteAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(function (DeleteBulkAction $action, Collection $records, DeleteAnalysisProfiles $delete): void {
                            try {
                                $action->reportBulkProcessingSuccessfulRecordsCount($delete->handle($records));
                            } catch (DefaultAnalysisProfileCannotBeDeleted $exception) {
                                $action->reportCompleteBulkProcessingFailure('default', $exception->getMessage());
                            }
                        }),
                ]),
            ]);
    }

    /**
     * Hidden for the loaded default; the stale-proof check is in
     * DeleteAnalysisProfiles.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->hidden(fn (AnalysisProfile $record): bool => $record->is_default)
            ->using(function (AnalysisProfile $record, DeleteAnalysisProfiles $delete): bool {
                try {
                    return $delete->handle([$record]) === 1;
                } catch (DefaultAnalysisProfileCannotBeDeleted $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();

                    return false;
                }
            });
    }

    public static function makeDefaultAction(): Action
    {
        return Action::make('makeDefault')
            ->label('Make default')
            ->icon(Heroicon::OutlinedStar)
            ->requiresConfirmation()
            ->modalDescription('The comparison uses the default profile when no other is chosen. The current default stops being the default.')
            ->hidden(fn (AnalysisProfile $record): bool => $record->is_default)
            ->action(function (AnalysisProfile $record, MakeAnalysisProfileDefault $makeDefault): void {
                $makeDefault->handle($record);

                Notification::make()
                    ->title(sprintf('"%s" is now the default profile', $record->name))
                    ->success()
                    ->send();
            });
    }
}
