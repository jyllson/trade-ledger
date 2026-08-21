<x-filament-panels::page>
    <x-filament::callout
        color="info"
        icon="heroicon-o-shield-check"
        heading="Read-only, on demand"
        description="Nothing on this page runs automatically. Every eToro request below is a real, live, GET-only HTTP request that only happens when you explicitly submit the action's form — rendering this page never contacts eToro. Ranking discovery pages a fixed page size of 20 with a 2-second pause between pages; a profile lookup is a single request."
    />

    <x-filament::section heading="Last discovery run">
        @if ($lastDiscoveryResult)
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Run</p>
                    <x-filament::link :href="$lastDiscoveryResult['run_url']" size="sm">
                        #{{ $lastDiscoveryResult['run_id'] }}
                    </x-filament::link>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Status</p>
                    <x-filament::badge :color="$lastDiscoveryResult['status'] === 'completed' ? 'success' : ($lastDiscoveryResult['status'] === 'partial' ? 'warning' : 'danger')">
                        {{ ucfirst($lastDiscoveryResult['status']) }}
                    </x-filament::badge>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Stop reason</p>
                    <p class="text-sm">{{ $lastDiscoveryResult['stop_reason'] }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Pages fetched</p>
                    <p class="text-sm">{{ $lastDiscoveryResult['pages_fetched'] }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Requests</p>
                    <p class="text-sm">{{ $lastDiscoveryResult['request_count'] }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Succeeded / Rejected</p>
                    <p class="text-sm">{{ $lastDiscoveryResult['success_count'] }} / {{ $lastDiscoveryResult['failure_count'] }}</p>
                </div>
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">No discovery run has been started this session. Use "Run discovery" above to start one.</p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Last profile lookup">
        @if ($lastProfileLookupResult)
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Run</p>
                    <x-filament::link :href="$lastProfileLookupResult['run_url']" size="sm">
                        #{{ $lastProfileLookupResult['run_id'] }}
                    </x-filament::link>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Username</p>
                    <p class="text-sm">{{ $lastProfileLookupResult['username'] }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Status</p>
                    <x-filament::badge :color="$lastProfileLookupResult['completed'] ? 'success' : 'danger'">
                        {{ ucfirst($lastProfileLookupResult['status']) }}
                    </x-filament::badge>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Stop reason</p>
                    <p class="text-sm">{{ $lastProfileLookupResult['stop_reason'] }}</p>
                </div>
                @if ($lastProfileLookupResult['completed'])
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Local match / enriched</p>
                        <x-filament::badge :color="$lastProfileLookupResult['matched_local_trader'] ? 'success' : 'gray'">
                            {{ $lastProfileLookupResult['matched_local_trader'] ? 'Yes' : 'No' }}
                        </x-filament::badge>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">PI / Verified</p>
                        <p class="text-sm">
                            {{ $lastProfileLookupResult['profile_is_popular_investor'] === null ? '—' : ($lastProfileLookupResult['profile_is_popular_investor'] ? 'Yes' : 'No') }}
                            /
                            {{ $lastProfileLookupResult['profile_is_verified'] === null ? '—' : ($lastProfileLookupResult['profile_is_verified'] ? 'Yes' : 'No') }}
                        </p>
                    </div>
                @else
                    <div class="sm:col-span-2 lg:col-span-4">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Result</p>
                        <p class="text-sm">The lookup did not complete — see the linked import run for details. No conclusion about a remote profile or local match can be drawn.</p>
                    </div>
                @endif
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">No profile lookup has been performed this session. Use "Lookup profile" above to run one.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
