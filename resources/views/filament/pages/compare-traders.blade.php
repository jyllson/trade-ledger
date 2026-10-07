{{--
    Inline styles on purpose (D-036): the admin panel has no custom Filament
    theme, so arbitrary Tailwind utilities are not compiled. No value is
    coloured as "best" — the only colours are statuses and filter outcomes
    (D-049).
--}}
@php
    $cell = 'padding: 0.5rem 0.625rem; text-align: left; vertical-align: top;';
    $label = 'padding: 0.5rem 0.625rem; text-align: left; vertical-align: top; min-width: 14rem; max-width: 18rem;';
    $muted = 'font-size: 0.75rem; opacity: 0.7;';
    $row = 'border-top: 1px solid rgba(127, 127, 127, 0.2);';
    $group = 'border-top: 1px solid rgba(127, 127, 127, 0.35); background: rgba(127, 127, 127, 0.08);';
@endphp
<x-filament-panels::page>
    <x-filament::section heading="Selection" description="2–10 traders. The selection is kept in the page URL, so the page can be reloaded or shared. Add traders with “Add trader”, or select them in Research → Traders and use the “Compare” bulk action.">
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;" data-compare-selection>
            @forelse ($chips as $chip)
                <span style="display: inline-flex; align-items: center; gap: 0.25rem;">
                    <x-filament::badge :color="$chip['known'] ? 'primary' : 'danger'">
                        @if ($chip['url'])
                            <a href="{{ $chip['url'] }}">{{ $chip['label'] }}</a>
                        @else
                            {{ $chip['label'] }}
                        @endif
                    </x-filament::badge>
                    <x-filament::icon-button
                        icon="heroicon-m-x-mark"
                        color="gray"
                        size="sm"
                        :label="'Remove '.$chip['label']"
                        wire:click="removeTrader({{ $chip['position'] }})"
                    />
                </span>
            @empty
                <span style="font-size: 0.875rem; opacity: 0.7;">No traders selected.</span>
            @endforelse
        </div>

        <div style="margin-top: 1rem; max-width: 28rem;">
            <label for="compare-profile" style="{{ $muted }} display: block; margin-bottom: 0.25rem;">Analysis profile (budget, target and filters)</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="compare-profile" wire:model.live="profile">
                    <option value="">Default profile</option>
                    @foreach ($profiles as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        @if ($profileWarning)
            <x-filament::callout color="warning" icon="heroicon-o-exclamation-triangle" heading="Unknown analysis profile" :description="$profileWarning" style="margin-top: 1rem;" />
        @endif
    </x-filament::section>

    @if ($rejection)
        <x-filament::callout
            color="danger"
            icon="heroicon-o-x-circle"
            :heading="$rejection['heading']"
            :description="$rejection['message']"
            data-compare-rejection
        />
    @elseif ($data === null)
        <x-filament::section>
            <x-filament::empty-state
                icon="heroicon-o-arrows-right-left"
                heading="No traders selected"
                description="Select 2–10 traders in Research → Traders and use the “Compare” bulk action, or add traders here with “Add trader”."
            />
        </x-filament::section>
    @else
        {{-- Observation periods: warning when they differ (PROJECT.md §15, §20 M5). --}}
        <x-filament::callout
            :color="$data['periodsDiffer'] ? 'warning' : 'gray'"
            :icon="$data['periodsDiffer'] ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-calendar'"
            :heading="$data['periodsDiffer'] ? 'Observation periods differ — values are not over the same window' : 'Observation periods match'"
            :description="$data['periodsDiffer']
                ? 'Every value is computed over that trader’s own stored period (and own latest snapshot). Comparing figures across different windows can mislead. '.($data['commonMonthly'] ? 'Common monthly period of all traders: '.$data['commonMonthly'].'.' : 'There is no monthly period common to all traders.')
                : 'All traders have the same stored monthly and daily periods and snapshots captured at the same instant.'"
            data-compare-periods
            :data-periods-differ="$data['periodsDiffer'] ? 'true' : 'false'"
        >
            <x-slot name="footer">
                <div style="overflow-x: auto;">
                    <table style="width: 100%; font-size: 0.8125rem; font-variant-numeric: tabular-nums; border-collapse: collapse;">
                        <thead>
                            <tr style="opacity: 0.7;">
                                <th style="{{ $cell }}">Trader</th>
                                <th style="{{ $cell }}">Monthly series (UTC months)</th>
                                <th style="{{ $cell }}">Daily series (UTC days)</th>
                                <th style="{{ $cell }}">Latest portfolio snapshot</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($data['traders'] as $trader)
                                <tr style="{{ $row }}">
                                    <td style="{{ $cell }} font-weight: 600;">{{ $trader['username'] }}</td>
                                    <td style="{{ $cell }}">{{ $trader['monthly'] }}</td>
                                    <td style="{{ $cell }}">{{ $trader['daily'] }}</td>
                                    <td style="{{ $cell }}">{{ $trader['snapshot'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-slot>
        </x-filament::callout>

        @if ($data['dataQuality'] !== [])
            <x-filament::callout
                color="danger"
                icon="heroicon-o-shield-exclamation"
                heading="Data-quality warnings"
                description="Figures below are shown, but rely on data that is stale, no longer visible, missing or affected by failed syncs."
                data-compare-data-quality
            >
                <x-slot name="footer">
                    <ul style="font-size: 0.8125rem; list-style: none; padding: 0; margin: 0; display: grid; gap: 0.5rem;">
                        @foreach ($data['dataQuality'] as $warning)
                            <li>
                                <span style="font-weight: 600;">{{ $warning['trader'] }}</span>
                                <ul style="list-style: disc; padding-left: 1.25rem;">
                                    @foreach ($warning['messages'] as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </li>
                        @endforeach
                    </ul>
                </x-slot>
            </x-filament::callout>
        @endif

        <x-filament::section
            heading="Comparison"
            :description="'Independent dimensions (PROJECT.md §14) — no overall score, no ranking. Profile: '.$data['profile']['name'].' · budget '.$data['profile']['budget'].' · target '.$data['profile']['target'].'. Generated '.$data['generatedAt'].' from stored data only ('.$data['methodologyVersion'].').'"
        >
            <div style="overflow-x: auto;">
                <table style="width: 100%; font-size: 0.8125rem; font-variant-numeric: tabular-nums; border-collapse: collapse;" data-compare-table>
                    <thead>
                        <tr>
                            <th style="{{ $label }} opacity: 0.7;">Metric</th>
                            @foreach ($data['traders'] as $trader)
                                <th style="{{ $cell }} min-width: 10rem;">
                                    <a href="{{ $trader['url'] }}" style="font-weight: 600;">{{ $trader['username'] }}</a>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['dimensions'] as $dimension)
                            <tr style="{{ $group }}">
                                <th colspan="{{ count($data['traders']) + 1 }}" style="{{ $cell }} font-weight: 600;">{{ $dimension['label'] }}</th>
                            </tr>
                            @foreach ($dimension['rows'] as $metricRow)
                                <tr style="{{ $row }}" data-metric="{{ $metricRow['key'] }}">
                                    <td style="{{ $label }}">
                                        <div>{{ $metricRow['label'] }}</div>
                                        @if ($metricRow['hint'])
                                            <div style="{{ $muted }}">{{ $metricRow['hint'] }}</div>
                                        @endif
                                    </td>
                                    @foreach ($metricRow['cells'] as $metricCell)
                                        <td style="{{ $cell }}" title="Observation: {{ $metricCell['period'] }}">
                                            @if ($metricCell['status'] === 'unavailable')
                                                <x-filament::badge color="gray" size="sm">Unavailable</x-filament::badge>
                                            @else
                                                <span style="white-space: nowrap;">{{ $metricCell['text'] }}</span>
                                                @if ($metricCell['status'] === 'partial')
                                                    <x-filament::badge color="warning" size="sm" style="display: inline-flex; margin-left: 0.25rem;">Partial</x-filament::badge>
                                                @endif
                                            @endif
                                            @if ($metricCell['reason'])
                                                <div style="{{ $muted }}">{{ $metricCell['reason'] }}</div>
                                            @endif
                                            @foreach ($metricCell['warnings'] as $warningText)
                                                <div style="font-size: 0.75rem; color: var(--warning-600);">⚠ {{ $warningText }}</div>
                                            @endforeach
                                            @if ($metricCell['show_period'])
                                                <div style="{{ $muted }}">{{ $metricCell['period'] }}</div>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($data['notSupported'] !== [])
                <p style="{{ $muted }} margin-top: 0.75rem;">
                    Data completeness counts only sources this application collects (profile, 24 complete months, daily data, live portfolio), each weighted 1. Not supported by this application and excluded for every trader: {{ implode(', ', $data['notSupported']) }}.
                </p>
            @endif
        </x-filament::section>

        <x-filament::section
            heading="Analysis profile filters"
            :description="'Profile “'.$data['profile']['name'].'”: each criterion is shown separately with its threshold and the trader’s actual value. Unknown is never counted as pass or fail; not-applied criteria have no threshold in this profile.'"
        >
            <div style="overflow-x: auto;">
                <table style="width: 100%; font-size: 0.8125rem; font-variant-numeric: tabular-nums; border-collapse: collapse;" data-compare-filters>
                    <thead>
                        <tr>
                            <th style="{{ $label }} opacity: 0.7;">Criterion (threshold)</th>
                            @foreach ($data['traders'] as $trader)
                                <th style="{{ $cell }} min-width: 10rem; font-weight: 600;">{{ $trader['username'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['filters'] as $filter)
                            <tr style="{{ $row }}">
                                <td style="{{ $label }}">
                                    <div>{{ $filter['label'] }}</div>
                                    <div style="{{ $muted }}">{{ $filter['threshold'] }}</div>
                                </td>
                                @foreach ($filter['cells'] as $filterCell)
                                    <td style="{{ $cell }}">
                                        @if ($filterCell)
                                            <x-filament::badge :color="$filterCell['color']" size="sm" style="display: inline-flex;">{{ $filterCell['outcome'] }}</x-filament::badge>
                                            @if ($filterCell['actual'] !== '—')
                                                <span style="margin-left: 0.25rem; white-space: nowrap;">{{ $filterCell['actual'] }}</span>
                                            @endif
                                            <div style="{{ $muted }}">{{ $filterCell['explanation'] }}</div>
                                            @foreach ($filterCell['warnings'] as $warningText)
                                                <div style="font-size: 0.75rem; color: var(--warning-600);">⚠ {{ $warningText }}</div>
                                            @endforeach
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        <tr style="{{ $group }}">
                            <td style="{{ $label }}">
                                <div style="font-weight: 600;">Summary of outcomes</div>
                                <div style="{{ $muted }}">Derived, unweighted (fail before unknown before pass) — not a score.</div>
                            </td>
                            @foreach ($data['verdicts'] as $verdict)
                                <td style="{{ $cell }}">{{ $verdict ?? '—' }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section
            heading="Equity index — monthly, one chart per trader"
            description="Each chart covers only that trader’s own stored period (see the heading); the x-axes are not aligned, so the curves are not a like-for-like comparison."
        >
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr)); gap: 1rem;">
                @foreach ($data['traders'] as $trader)
                    @livewire(\App\Filament\Resources\Traders\Widgets\TraderComparisonEquityChart::class, [
                        'record' => $trader['record'],
                        'period' => $trader['has_monthly'] ? $trader['monthly'] : '',
                    ], key('compare-equity-'.$trader['id']))
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
