<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Application\Imports\DiscoverEtoroTraders;
use App\Application\Imports\DiscoverEtoroTradersRequest;
use App\Application\Traders\LookupEtoroTraderProfile;
use App\Application\Traders\LookupEtoroTraderProfileStopReason;
use App\Application\Traders\TraderUsername;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRunStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Throwable;
use UnitEnum;

/**
 * Manual entry point for live, read-only eToro ranking discovery and
 * trader profile lookups. Rendering this page NEVER makes an HTTP call —
 * every request only ever happens inside an explicit action submission,
 * and only ever through DiscoverEtoroTraders / LookupEtoroTraderProfile.
 * Neither result is ever kept as a raw DTO in Livewire state — only a
 * small, sanitized, scalar snapshot of each is.
 */
class DiscoverTraders extends Page
{
    protected string $view = 'filament.pages.discover-traders';

    protected static ?string $navigationLabel = 'Discover Traders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Research';

    protected static ?int $navigationSort = 3;

    /**
     * @var array{run_id: int, run_url: string, status: string, stop_reason: string, pages_fetched: int, request_count: int, success_count: int, failure_count: int}|null
     */
    public ?array $lastDiscoveryResult = null;

    /**
     * @var array{run_id: int, run_url: string, username: string, status: string, stop_reason: string, completed: bool, matched_local_trader: bool, profile_is_popular_investor: ?bool, profile_is_verified: ?bool}|null
     */
    public ?array $lastProfileLookupResult = null;

    protected function getHeaderActions(): array
    {
        return [
            $this->runDiscoveryAction(),
            $this->lookupProfileAction(),
        ];
    }

    private function runDiscoveryAction(): Action
    {
        return Action::make('runDiscovery')
            ->label('Run discovery')
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->modalHeading('Run live eToro ranking discovery')
            ->modalDescription('This performs read-only live eToro GET requests only — nothing is ever written back to eToro. Page size is fixed at 20, with a 2-second pause between pages.')
            ->modalSubmitActionLabel('Run discovery')
            ->schema([
                TextInput::make('period')
                    ->label('Period')
                    ->default('lastYear')
                    ->required(),
                TextInput::make('start_page')
                    ->label('Start page')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->required(),
                TextInput::make('max_pages')
                    ->label('Max pages')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->maxValue(DiscoverEtoroTradersRequest::MAX_PAGES_CEILING)
                    ->required(),
                TextInput::make('sort')
                    ->label('Sort (optional)'),
                TextInput::make('country')
                    ->label('Country filter (optional)'),
            ])
            ->action(function (array $data): void {
                try {
                    $request = new DiscoverEtoroTradersRequest(
                        period: (string) $data['period'],
                        startPage: (int) $data['start_page'],
                        maxPages: (int) $data['max_pages'],
                        sort: filled($data['sort'] ?? null) ? (string) $data['sort'] : null,
                        country: filled($data['country'] ?? null) ? (string) $data['country'] : null,
                    );
                } catch (InvalidArgumentException) {
                    Notification::make()
                        ->title('Discovery input is invalid')
                        ->body('Check the period, start page (>=1), and max pages (1-20).')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $result = app(DiscoverEtoroTraders::class)->handle($request);
                } catch (Throwable) {
                    Notification::make()
                        ->title('Discovery could not be completed')
                        ->body('An unexpected error occurred while completing this operation. Please try again shortly.')
                        ->danger()
                        ->send();

                    return;
                }

                $runUrl = ImportRunResource::getUrl('view', ['record' => $result->importRun->id]);

                $this->lastDiscoveryResult = [
                    'run_id' => $result->importRun->id,
                    'run_url' => $runUrl,
                    'status' => $result->importRun->status->value,
                    'stop_reason' => $result->stopReason->value,
                    'pages_fetched' => $result->pagesFetched,
                    'request_count' => $result->importRun->request_count,
                    'success_count' => $result->importRun->success_count,
                    'failure_count' => $result->importRun->failure_count,
                ];

                Notification::make()
                    ->title('Discovery finished: '.ucfirst($result->importRun->status->value))
                    ->body("Pages fetched: {$result->pagesFetched}. Succeeded: {$result->importRun->success_count}. Rejected: {$result->importRun->failure_count}.")
                    ->status(self::notificationStatusForImportRunStatus($result->importRun->status))
                    ->actions([
                        Action::make('viewRun')
                            ->label('View run')
                            ->url($runUrl),
                    ])
                    ->send();
            });
    }

    private function lookupProfileAction(): Action
    {
        return Action::make('lookupProfile')
            ->label('Lookup profile')
            ->icon(Heroicon::OutlinedUser)
            ->color('gray')
            ->modalHeading('Lookup an eToro trader profile')
            ->modalDescription('This performs a single read-only live eToro GET request for the given username. An unknown remote profile is still recorded in the import history, but never creates a local trader.')
            ->modalSubmitActionLabel('Lookup profile')
            ->schema([
                TextInput::make('username')
                    ->label('Username')
                    ->required(),
            ])
            ->action(function (array $data): void {
                try {
                    $username = new TraderUsername((string) $data['username']);
                } catch (InvalidArgumentException) {
                    Notification::make()
                        ->title('Username is invalid')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $result = app(LookupEtoroTraderProfile::class)->handle($username);
                } catch (Throwable) {
                    Notification::make()
                        ->title('Profile lookup could not be completed')
                        ->body('An unexpected error occurred while completing this operation. Please try again shortly.')
                        ->danger()
                        ->send();

                    return;
                }

                $runUrl = ImportRunResource::getUrl('view', ['record' => $result->importRun->id]);
                $completed = $result->stopReason === LookupEtoroTraderProfileStopReason::Completed;

                $this->lastProfileLookupResult = [
                    'run_id' => $result->importRun->id,
                    'run_url' => $runUrl,
                    'username' => $username->value,
                    'status' => $result->importRun->status->value,
                    'stop_reason' => $result->stopReason->value,
                    'completed' => $completed,
                    'matched_local_trader' => $result->matchedTrader !== null,
                    'profile_is_popular_investor' => $result->profile?->isPopularInvestor,
                    'profile_is_verified' => $result->profile?->isVerified,
                ];

                // Branches on stopReason FIRST. A non-Completed stop reason
                // does not necessarily mean eToro was never reached —
                // mapping, identity-mismatch, and unexpected-response
                // failures can all happen AFTER a real HTTP response came
                // back. What actually distinguishes Completed is that it is
                // the only outcome with a validated, identity-matching
                // MAPPED profile — the only basis this UI ever has for
                // match/no-match language. Any other stop reason must never
                // be phrased as if a remote profile was checked and simply
                // had no local match.
                if (! $completed) {
                    Notification::make()
                        ->title('Profile lookup did not complete')
                        ->body('See the linked import run for details.')
                        ->warning()
                        ->actions([
                            Action::make('viewRun')
                                ->label('View run')
                                ->url($runUrl),
                        ])
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Profile lookup completed')
                    ->body($result->matchedTrader !== null
                        ? 'The eToro profile matched a locally-known trader and its profile fields were refreshed.'
                        : 'The eToro profile did not match any locally-known trader; no trader was created.')
                    ->success()
                    ->actions([
                        Action::make('viewRun')
                            ->label('View run')
                            ->url($runUrl),
                    ])
                    ->send();
            });
    }

    private static function notificationStatusForImportRunStatus(ImportRunStatus $status): string
    {
        return match ($status) {
            ImportRunStatus::Completed => 'success',
            ImportRunStatus::Partial => 'warning',
            ImportRunStatus::Failed => 'danger',
            // A finalized DiscoverEtoroTradersResult is never Pending or
            // Running, but a defensive, non-misleading fallback is kept
            // rather than assuming success.
            ImportRunStatus::Pending, ImportRunStatus::Running => 'warning',
        };
    }
}
