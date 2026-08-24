<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders;

use App\Filament\Resources\Traders\Pages\ListTraders;
use App\Filament\Resources\Traders\Pages\ViewTrader;
use App\Filament\Resources\Traders\Schemas\TraderInfolist;
use App\Filament\Resources\Traders\Tables\TradersTable;
use App\Models\Trader;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only research record for a locally-imported eToro trader. List and
 * View pages only — no Create/Edit/Delete/replicate routes exist at all,
 * and no bulk-delete action is ever registered. The only mutations this
 * resource ever performs go through App\Application\Traders\
 * ChangeTraderStatus (local triage) and App\Application\Traders\
 * LookupEtoroTraderProfile (remote profile enrichment) — see
 * Tables\TradersTable.
 */
class TraderResource extends Resource
{
    protected static ?string $model = Trader::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Traders';

    protected static string|UnitEnum|null $navigationGroup = 'Research';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'username';

    public static function infolist(Schema $schema): Schema
    {
        return TraderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TradersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTraders::route('/'),
            'view' => ViewTrader::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
