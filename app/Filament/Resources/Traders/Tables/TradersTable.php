<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders\Tables;

use App\Application\Traders\ChangeTraderStatus;
use App\Application\Traders\EvaluateTraderProfileFreshness;
use App\Application\Traders\LookupEtoroTraderProfile;
use App\Application\Traders\LookupEtoroTraderProfileStopReason;
use App\Application\Traders\ProfileFreshness;
use App\Application\Traders\TraderUsername;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\Trader;
use App\Models\TraderStatus;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

/**
 * Read-only trader table. The only writes this table ever triggers go
 * through ChangeTraderStatus (local triage) or LookupEtoroTraderProfile
 * (remote, read-only eToro profile lookup) — never a direct Trader
 * mutation, and profile freshness is always computed by
 * EvaluateTraderProfileFreshness, never here.
 */
class TradersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('username')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (TraderStatus $state): string => self::statusLabel($state))
                    ->color(fn (TraderStatus $state): string => self::statusColor($state))
                    ->sortable(),
                TextColumn::make('copiers_count')
                    ->label('Copiers')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('ranking_type')
                    ->label('Ranking type')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('ranking_sub_type')
                    ->label('Ranking sub-type')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('profile_is_popular_investor')
                    ->label('PI')
                    ->boolean()
                    ->toggleable(),
                IconColumn::make('profile_is_verified')
                    ->label('Verified')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('profile_country_code')
                    ->label('Country code')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('profile_language_iso_code')
                    ->label('Language')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_seen_at')
                    ->label('Last seen (ranking)')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('freshness')
                    ->label('Profile freshness')
                    ->badge()
                    ->state(fn (Trader $record): ProfileFreshness => app(EvaluateTraderProfileFreshness::class)->handle($record->profile_synced_at))
                    ->formatStateUsing(fn (ProfileFreshness $state): string => self::freshnessLabel($state))
                    ->color(fn (ProfileFreshness $state): string => self::freshnessColor($state)),
                TextColumn::make('profile_synced_at')
                    ->label('Profile synced at')
                    ->dateTime()
                    ->placeholder('Never synced')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        TraderStatus::Candidate->value => self::statusLabel(TraderStatus::Candidate),
                        TraderStatus::Watched->value => self::statusLabel(TraderStatus::Watched),
                        TraderStatus::Ignored->value => self::statusLabel(TraderStatus::Ignored),
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                self::changeStatusAction(TraderStatus::Candidate, 'markCandidate', 'Mark candidate', Heroicon::OutlinedFlag, 'gray'),
                self::changeStatusAction(TraderStatus::Watched, 'markWatched', 'Watch', Heroicon::OutlinedEye, 'warning'),
                self::changeStatusAction(TraderStatus::Ignored, 'markIgnored', 'Ignore', Heroicon::OutlinedEyeSlash, 'danger')
                    ->requiresConfirmation()
                    ->modalHeading('Ignore this trader?')
                    ->modalDescription('The trader will be marked Ignored locally. This does not affect eToro in any way and can be reversed at any time.'),
                self::lookupProfileAction(),
            ])
            ->emptyStateHeading('No traders imported yet')
            ->emptyStateDescription('Run Discover Traders to import eToro ranking pages.')
            ->emptyStateIcon(Heroicon::OutlinedUserGroup);
    }

    private static function changeStatusAction(TraderStatus $target, string $name, string $label, Heroicon $icon, string $color): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (Trader $record): bool => $record->status !== $target)
            ->action(function (Trader $record) use ($target, $label): void {
                app(ChangeTraderStatus::class)->handle($record, $target);

                Notification::make()
                    ->title("Trader status updated: {$label}")
                    ->success()
                    ->send();
            });
    }

    private static function lookupProfileAction(): Action
    {
        return Action::make('lookupProfile')
            ->label('Lookup eToro profile')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (Trader $record): void {
                try {
                    $result = app(LookupEtoroTraderProfile::class)->handle(new TraderUsername($record->username));
                } catch (Throwable) {
                    Notification::make()
                        ->title('Profile lookup could not be completed')
                        ->body('An unexpected error occurred while completing this operation. Please try again shortly.')
                        ->danger()
                        ->send();

                    return;
                }

                self::notifyProfileLookupResult($result->stopReason, $result->matchedTrader !== null, $result->importRun->id);
            });
    }

    private static function notifyProfileLookupResult(LookupEtoroTraderProfileStopReason $stopReason, bool $matchedTrader, int $importRunId): void
    {
        if ($stopReason !== LookupEtoroTraderProfileStopReason::Completed) {
            Notification::make()
                ->title('Profile lookup did not complete')
                ->body('The eToro profile lookup stopped before completing. See the linked import run for details.')
                ->warning()
                ->actions([
                    Action::make('viewRun')
                        ->label('View import run')
                        ->url(ImportRunResource::getUrl('view', ['record' => $importRunId])),
                ])
                ->send();

            return;
        }

        Notification::make()
            ->title($matchedTrader ? 'Profile synced' : 'Profile fetched — no local match')
            ->body($matchedTrader
                ? 'The local trader record was refreshed with the observed eToro profile fields.'
                : 'The eToro profile was fetched but does not match any locally-known trader; no trader was created.')
            ->success()
            ->actions([
                Action::make('viewRun')
                    ->label('View import run')
                    ->url(ImportRunResource::getUrl('view', ['record' => $importRunId])),
            ])
            ->send();
    }

    private static function statusLabel(TraderStatus $status): string
    {
        return match ($status) {
            TraderStatus::Candidate => 'Candidate',
            TraderStatus::Watched => 'Watched',
            TraderStatus::Ignored => 'Ignored',
        };
    }

    private static function statusColor(TraderStatus $status): string
    {
        return match ($status) {
            TraderStatus::Candidate => 'gray',
            TraderStatus::Watched => 'warning',
            TraderStatus::Ignored => 'danger',
        };
    }

    private static function freshnessLabel(ProfileFreshness $freshness): string
    {
        return match ($freshness) {
            ProfileFreshness::NeverSynced => 'Never synced',
            ProfileFreshness::Fresh => 'Fresh',
            ProfileFreshness::Stale => 'Stale',
        };
    }

    private static function freshnessColor(ProfileFreshness $freshness): string
    {
        return match ($freshness) {
            ProfileFreshness::NeverSynced => 'gray',
            ProfileFreshness::Fresh => 'success',
            ProfileFreshness::Stale => 'warning',
        };
    }
}
