<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center gap-x-4">
            <x-filament::badge :color="$writeAllowed ? 'danger' : 'success'" size="lg">
                {{ $writeAllowed ? 'Write mode' : 'Read-only analytics mode' }}
            </x-filament::badge>

            <div class="text-sm">
                <p class="font-medium">
                    eToro write operations are blocked.
                </p>
                <p class="text-gray-500 dark:text-gray-400">
                    This application only reads and analyzes data ({{ $environment }} environment).
                    No trading, copying, deposit, or withdrawal actions are possible.
                </p>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
