<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnalysisProfiles;

use App\Filament\Resources\AnalysisProfiles\Pages\CreateAnalysisProfile;
use App\Filament\Resources\AnalysisProfiles\Pages\EditAnalysisProfile;
use App\Filament\Resources\AnalysisProfiles\Pages\ListAnalysisProfiles;
use App\Filament\Resources\AnalysisProfiles\Schemas\AnalysisProfileForm;
use App\Filament\Resources\AnalysisProfiles\Tables\AnalysisProfilesTable;
use App\Models\AnalysisProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Analysis profiles — budget, coverage target and restrictive criteria
 * for the trader comparison filters (PROJECT.md §11, §15 Settings;
 * docs/DECISIONS.md D-048). Exactly one profile is the default.
 */
class AnalysisProfileResource extends Resource
{
    protected static ?string $model = AnalysisProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Analysis profiles';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AnalysisProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AnalysisProfilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnalysisProfiles::route('/'),
            'create' => CreateAnalysisProfile::route('/create'),
            'edit' => EditAnalysisProfile::route('/{record}/edit'),
        ];
    }
}
