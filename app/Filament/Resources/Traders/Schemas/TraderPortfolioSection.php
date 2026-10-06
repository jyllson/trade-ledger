<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders\Schemas;

use App\Models\PerformanceVisibility;
use App\Models\Trader;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

/**
 * Portfolio sync status of the trader view (D-043), next to "Performance
 * sync". The stored portfolio itself is rendered by the TraderPortfolio
 * footer widget; nothing here calls the eToro API.
 */
final class TraderPortfolioSection
{
    /**
     * @return list<Section>
     */
    public static function make(): array
    {
        return [
            Section::make('Portfolio sync')
                ->description('Live portfolio snapshots are stored only by the queued “Sync portfolio” action; this page and the copy simulator read stored snapshots only.')
                ->schema([
                    TextEntry::make('portfolio_visibility')
                        ->label('Visibility')
                        ->badge()
                        ->state(fn (Trader $record): string => match ($record->portfolio_visibility) {
                            null => 'Never synced',
                            PerformanceVisibility::Available => 'Available',
                            PerformanceVisibility::Private => 'Private (opted out)',
                            PerformanceVisibility::NotFound => 'Not found',
                        })
                        ->color(fn (Trader $record): string => match ($record->portfolio_visibility) {
                            null => 'gray',
                            PerformanceVisibility::Available => 'success',
                            PerformanceVisibility::Private, PerformanceVisibility::NotFound => 'warning',
                        }),
                    TextEntry::make('portfolio_synced_at')
                        ->label('Last successful sync')
                        ->dateTime()
                        ->placeholder('Never synced'),
                ])
                ->columns(2),
        ];
    }
}
