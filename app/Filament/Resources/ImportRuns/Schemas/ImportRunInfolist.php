<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportRuns\Schemas;

use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Models\ImportRun;
use App\Models\ImportRunStatus;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A strict whitelist of sanitized ImportRun fields — never the raw
 * `metadata` JSON blob. Every entry below reads one specific, previously
 * documented, already-sanitized key (see App\Application\Imports\
 * DiscoverEtoroTraders and App\Application\Traders\
 * LookupEtoroTraderProfile), never the full array.
 */
class ImportRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Run')
                    ->schema([
                        TextEntry::make('id')
                            ->label('Run'),
                        TextEntry::make('source'),
                        TextEntry::make('type')
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                'rankings_discovery' => 'Discovery (aggregate)',
                                'rankings' => 'Ranking page',
                                'profile' => 'Profile lookup',
                                default => $state,
                            })
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (ImportRunStatus $state): string => ucfirst($state->value))
                            ->color(fn (ImportRunStatus $state): string => match ($state) {
                                ImportRunStatus::Pending => 'gray',
                                ImportRunStatus::Running => 'info',
                                ImportRunStatus::Completed => 'success',
                                ImportRunStatus::Partial => 'warning',
                                ImportRunStatus::Failed => 'danger',
                            }),
                        TextEntry::make('request_count')
                            ->label('Requests'),
                        TextEntry::make('success_count')
                            ->label('Succeeded'),
                        TextEntry::make('failure_count')
                            ->label('Rejected'),
                        TextEntry::make('started_at')
                            ->dateTime(),
                        TextEntry::make('finished_at')
                            ->dateTime()
                            ->placeholder('Still running'),
                        TextEntry::make('error_summary')
                            ->label('Error summary')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Lineage')
                    ->schema([
                        TextEntry::make('parent_import_run_id')
                            ->label('Parent run')
                            ->placeholder('—')
                            ->url(fn (ImportRun $record): ?string => $record->parent_import_run_id === null
                                ? null
                                : ImportRunResource::getUrl('view', ['record' => $record->parent_import_run_id])),
                        TextEntry::make('retry_of_import_run_id')
                            ->label('Retry of run')
                            ->placeholder('—')
                            ->url(fn (ImportRun $record): ?string => $record->retry_of_import_run_id === null
                                ? null
                                : ImportRunResource::getUrl('view', ['record' => $record->retry_of_import_run_id])),
                    ])
                    ->columns(2),

                Section::make('Query')
                    ->schema([
                        TextEntry::make('query_period')
                            ->label('Period')
                            ->state(fn (ImportRun $record): mixed => data_get($record->metadata, 'query.period'))
                            ->placeholder('—')
                            ->visible(fn (ImportRun $record): bool => in_array($record->type, ['rankings_discovery', 'rankings'], true)),
                        TextEntry::make('query_start_page')
                            ->label('Start page')
                            ->state(fn (ImportRun $record): mixed => data_get($record->metadata, 'query.start_page'))
                            ->placeholder('—')
                            ->visible(fn (ImportRun $record): bool => $record->type === 'rankings_discovery'),
                        TextEntry::make('query_page')
                            ->label('Page')
                            ->state(fn (ImportRun $record): mixed => data_get($record->metadata, 'query.page'))
                            ->placeholder('—')
                            ->visible(fn (ImportRun $record): bool => $record->type === 'rankings'),
                        TextEntry::make('query_page_size')
                            ->label('Page size')
                            ->state(fn (ImportRun $record): mixed => data_get($record->metadata, 'query.page_size') ?? data_get($record->metadata, 'query.pageSize'))
                            ->placeholder('—')
                            ->visible(fn (ImportRun $record): bool => in_array($record->type, ['rankings_discovery', 'rankings'], true)),
                        TextEntry::make('query_max_pages')
                            ->label('Max pages')
                            ->state(fn (ImportRun $record): mixed => data_get($record->metadata, 'query.max_pages'))
                            ->placeholder('—')
                            ->visible(fn (ImportRun $record): bool => $record->type === 'rankings_discovery'),
                        TextEntry::make('query_sort')
                            ->label('Sort')
                            ->state(fn (ImportRun $record): mixed => data_get($record->metadata, 'query.sort'))
                            ->placeholder('—')
                            ->visible(fn (ImportRun $record): bool => in_array($record->type, ['rankings_discovery', 'rankings'], true)),
                        TextEntry::make('query_country')
                            ->label('Country filter')
                            ->state(fn (ImportRun $record): mixed => data_get($record->metadata, 'query.country'))
                            ->placeholder('—')
                            ->visible(fn (ImportRun $record): bool => in_array($record->type, ['rankings_discovery', 'rankings'], true)),
                        TextEntry::make('query_username')
                            ->label('Username')
                            ->state(fn (ImportRun $record): mixed => data_get($record->metadata, 'query.username'))
                            ->placeholder('—')
                            ->visible(fn (ImportRun $record): bool => $record->type === 'profile'),
                    ])
                    ->columns(3)
                    ->visible(fn (ImportRun $record): bool => $record->metadata !== null),

                Section::make('Outcome')
                    ->schema([
                        TextEntry::make('stop_reason')
                            ->label('Stop reason')
                            ->state(fn (ImportRun $record): mixed => data_get($record->metadata, 'stop_reason'))
                            ->placeholder('—'),
                        TextEntry::make('pages_fetched')
                            ->label('Pages fetched')
                            ->state(fn (ImportRun $record): mixed => data_get($record->metadata, 'pages_fetched'))
                            ->placeholder('—')
                            ->visible(fn (ImportRun $record): bool => $record->type === 'rankings_discovery'),
                        IconEntry::make('matched_stored_trader')
                            ->label('Matched a stored trader')
                            ->boolean()
                            ->state(fn (ImportRun $record): mixed => data_get($record->metadata, 'matched_stored_trader'))
                            ->visible(fn (ImportRun $record): bool => $record->type === 'profile'),
                        IconEntry::make('retryable')
                            ->label('Retryable')
                            ->boolean()
                            ->state(fn (ImportRun $record): mixed => data_get($record->metadata, 'retryable'))
                            ->visible(fn (ImportRun $record): bool => $record->type === 'rankings_discovery'),
                        TextEntry::make('request_error_category')
                            ->label('Request error category')
                            ->state(fn (ImportRun $record): mixed => data_get($record->metadata, 'request_error_category'))
                            ->placeholder('—')
                            ->visible(fn (ImportRun $record): bool => $record->type === 'rankings_discovery'),
                        TextEntry::make('retry_not_before')
                            ->label('Retry not before')
                            ->dateTime()
                            ->state(fn (ImportRun $record): mixed => data_get($record->metadata, 'retry_not_before'))
                            ->placeholder('—')
                            ->visible(fn (ImportRun $record): bool => $record->type === 'rankings_discovery'),
                    ])
                    ->columns(3)
                    ->visible(fn (ImportRun $record): bool => $record->metadata !== null),
            ]);
    }
}
