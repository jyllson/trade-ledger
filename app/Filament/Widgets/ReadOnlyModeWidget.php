<?php

namespace App\Filament\Widgets;

use App\Application\DemoCopy\DemoCopyAvailability;
use App\Etoro\EtoroWriteGuard;
use Filament\Widgets\Widget;

class ReadOnlyModeWidget extends Widget
{
    protected string $view = 'filament.widgets.read-only-mode-widget';

    /**
     * The safety banner must be visible immediately, without lazy loading.
     */
    protected static bool $isLazy = false;

    protected static ?int $sort = -3;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{writeAllowed: bool, demoCopyEnabled: bool, environment: string}
     */
    protected function getViewData(): array
    {
        return [
            'writeAllowed' => app(EtoroWriteGuard::class)->allowsWrite(),
            'demoCopyEnabled' => app(DemoCopyAvailability::class)->isEnabled(),
            'environment' => (string) config('etoro.environment'),
        ];
    }
}
