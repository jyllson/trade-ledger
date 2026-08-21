<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders\Schemas;

use App\Application\Traders\EvaluateTraderProfileFreshness;
use App\Application\Traders\ProfileFreshness;
use App\Models\Trader;
use App\Models\TraderStatus;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TraderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Local triage')
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (TraderStatus $state): string => ucfirst($state->value))
                            ->color(fn (TraderStatus $state): string => match ($state) {
                                TraderStatus::Candidate => 'gray',
                                TraderStatus::Watched => 'warning',
                                TraderStatus::Ignored => 'danger',
                            }),
                    ]),
                Section::make('Observed ranking identity')
                    ->schema([
                        TextEntry::make('external_cid')
                            ->label('eToro CID'),
                        TextEntry::make('username'),
                        TextEntry::make('ranking_type')
                            ->label('Ranking type'),
                        TextEntry::make('ranking_sub_type')
                            ->label('Ranking sub-type'),
                        TextEntry::make('copiers_count')
                            ->label('Copiers')
                            ->numeric(),
                        TextEntry::make('first_seen_at')
                            ->label('First seen (ranking)')
                            ->dateTime(),
                        TextEntry::make('last_seen_at')
                            ->label('Last seen (ranking)')
                            ->dateTime(),
                    ])
                    ->columns(2),
                Section::make('Observed eToro profile')
                    ->description('Only fields actually observed from the eToro profile API — never invented display data.')
                    ->schema([
                        TextEntry::make('freshness')
                            ->label('Profile freshness')
                            ->badge()
                            ->state(fn (Trader $record): ProfileFreshness => app(EvaluateTraderProfileFreshness::class)->handle($record->profile_synced_at))
                            ->formatStateUsing(fn (ProfileFreshness $state): string => match ($state) {
                                ProfileFreshness::NeverSynced => 'Never synced',
                                ProfileFreshness::Fresh => 'Fresh',
                                ProfileFreshness::Stale => 'Stale',
                            })
                            ->color(fn (ProfileFreshness $state): string => match ($state) {
                                ProfileFreshness::NeverSynced => 'gray',
                                ProfileFreshness::Fresh => 'success',
                                ProfileFreshness::Stale => 'warning',
                            }),
                        TextEntry::make('profile_synced_at')
                            ->label('Profile synced at')
                            ->dateTime()
                            ->placeholder('Never synced'),
                        TextEntry::make('profile_gcid')
                            ->label('eToro GCID')
                            ->placeholder('Not yet observed')
                            ->helperText('An independent eToro identifier, observed only — never compared with or used to resolve the ranking CID above.'),
                        IconEntry::make('profile_is_popular_investor')
                            ->label('Popular Investor')
                            ->boolean()
                            ->placeholder('Not yet observed'),
                        IconEntry::make('profile_is_verified')
                            ->label('Verified')
                            ->boolean()
                            ->placeholder('Not yet observed'),
                        TextEntry::make('profile_country_code')
                            ->label('Country code')
                            ->placeholder('Not yet observed'),
                        TextEntry::make('profile_language_iso_code')
                            ->label('Language')
                            ->placeholder('Not yet observed'),
                    ])
                    ->columns(2),
            ]);
    }
}
