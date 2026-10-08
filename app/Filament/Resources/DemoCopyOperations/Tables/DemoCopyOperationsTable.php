<?php

declare(strict_types=1);

namespace App\Filament\Resources\DemoCopyOperations\Tables;

use App\Application\DemoCopy\DemoCopyAmount;
use App\Application\DemoCopy\DemoCopyAvailability;
use App\Application\DemoCopy\DemoCopyRefused;
use App\Application\DemoCopy\PollDemoCopyOutcome;
use App\Filament\Resources\Traders\TraderResource;
use App\Models\DemoCopyOperation;
use App\Models\DemoCopyOperationStatus;
use App\Models\DemoCopyOperationType;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Demo copy audit table (D-050), newest first. Writes nothing itself; the
 * „Check outcome“ row action runs one PollDemoCopyOutcome.
 */
class DemoCopyOperationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (DemoCopyOperationType $state): string => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    // A close is only ever acknowledged, never confirmed (D-050).
                    ->formatStateUsing(fn (DemoCopyOperationStatus $state, DemoCopyOperation $record): string => $record->type === DemoCopyOperationType::Close && $state === DemoCopyOperationStatus::Accepted
                        ? 'Requested — not confirmed'
                        : ucfirst($state->value))
                    ->color(fn (DemoCopyOperationStatus $state, DemoCopyOperation $record): string => $record->type === DemoCopyOperationType::Close && $state === DemoCopyOperationStatus::Accepted
                        ? 'warning'
                        : $state->color()),
                TextColumn::make('trader_username')
                    ->label('Trader')
                    ->placeholder('—')
                    ->searchable()
                    ->url(fn (DemoCopyOperation $record): ?string => $record->trader_id === null
                        ? null
                        : TraderResource::getUrl('view', ['record' => $record->trader_id])),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn (?int $state): string => DemoCopyAmount::format($state))
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('mirror_id')
                    ->label('Mirror')
                    ->placeholder('—'),
                TextColumn::make('unregister_type')
                    ->label('Close type')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('http_status')
                    ->label('HTTP')
                    ->placeholder('—'),
                TextColumn::make('parent_operation_id')
                    ->label('Parent #')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('reference_id')
                    ->label('referenceID')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('request_id')
                    ->label('x-request-id')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('client_request_id')
                    ->label('clientRequestID')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('error_code')
                    ->label('eToro error code')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('transport_outcome')
                    ->label('Transport')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.name')
                    ->label('Started by')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(DemoCopyOperationType::cases())->mapWithKeys(fn (DemoCopyOperationType $type): array => [$type->value => $type->label()])->all()),
                SelectFilter::make('status')
                    ->options(collect(DemoCopyOperationStatus::cases())->mapWithKeys(fn (DemoCopyOperationStatus $status): array => [$status->value => ucfirst($status->value)])->all()),
            ])
            ->recordActions([
                Action::make('checkOutcome')
                    ->label('Check outcome')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (DemoCopyOperation $record): bool => $record->type->isRegistration()
                        && $record->reference_id !== null
                        && $record->status->awaitsOutcome())
                    ->disabled(fn (): bool => ! app(DemoCopyAvailability::class)->isEnabled())
                    ->tooltip(fn (): ?string => app(DemoCopyAvailability::class)->disabledReason())
                    ->action(function (DemoCopyOperation $record): void {
                        $userId = auth()->id();

                        try {
                            $poll = app(PollDemoCopyOutcome::class)->handle($record, is_int($userId) ? $userId : null);
                        } catch (DemoCopyRefused $refused) {
                            Notification::make()->title('Nothing was sent to eToro')->body($refused->getMessage())->danger()->send();

                            return;
                        }

                        $record->refresh();

                        Notification::make()
                            ->title('Outcome: '.$record->status->value)
                            ->body($poll->reason ?? $record->reason)
                            ->status($record->status === DemoCopyOperationStatus::Succeeded ? 'success' : 'warning')
                            ->send();
                    }),
            ]);
    }
}
