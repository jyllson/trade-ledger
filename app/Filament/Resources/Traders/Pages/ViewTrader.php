<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders\Pages;

use App\Application\Traders\QueueTraderPerformanceSync;
use App\Filament\Resources\Traders\TraderResource;
use App\Filament\Resources\Traders\Widgets\TraderDrawdownChart;
use App\Filament\Resources\Traders\Widgets\TraderEquityChart;
use App\Filament\Resources\Traders\Widgets\TraderMonthlyReturns;
use App\Models\Trader;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewTrader extends ViewRecord
{
    protected static string $resource = TraderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncPerformance')
                ->label('Sync performance')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (Trader $record, QueueTraderPerformanceSync $queueTraderPerformanceSync): void {
                    if (! $queueTraderPerformanceSync->handle($record)) {
                        Notification::make()
                            ->title('eToro integration is disabled — nothing was queued.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Performance sync queued')
                        ->body('Monthly and daily series will update when the queue worker runs the job. Reload the page afterwards.')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            TraderEquityChart::class,
            TraderDrawdownChart::class,
            TraderMonthlyReturns::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
