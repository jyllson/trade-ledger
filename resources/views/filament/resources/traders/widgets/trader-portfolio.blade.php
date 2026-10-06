{{--
    Inline styles on purpose (D-036): the admin panel has no custom Filament
    theme, so arbitrary Tailwind utilities are not compiled.
--}}
@php
    $cell = 'padding: 0.375rem 0.5rem; text-align: left; vertical-align: top;';
    $num = 'padding: 0.375rem 0.5rem; text-align: right; vertical-align: top; white-space: nowrap;';
    $muted = 'font-size: 0.75rem; opacity: 0.7;';
    $row = 'border-top: 1px solid rgba(127, 127, 127, 0.2);';
@endphp
<x-filament-widgets::widget>
    <x-filament::section heading="Portfolio" description="Latest STORED live-portfolio snapshot — never fetched on page load. Position weights are shares of the whole portfolio as reported by eToro; concentration and leverage use the invested-only basis (wᵢ = position weight ÷ Σ position weights, cash excluded; D-041).">
        @if (! $available)
            <p style="font-size: 0.875rem; opacity: 0.7;">{{ $emptyMessage }}</p>
        @else
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 0.75rem; font-size: 0.875rem;">
                <div><div style="{{ $muted }}">Snapshot captured</div><div>{{ $snapshot['captured_at'] }}</div></div>
                <div><div style="{{ $muted }}">Last confirmed unchanged</div><div>{{ $snapshot['last_confirmed_at'] }}</div></div>
                <div><div style="{{ $muted }}">Invested (Σ positions)</div><div>{{ $basis['invested'] }}</div></div>
                <div><div style="{{ $muted }}">Cash</div><div>{{ $basis['cash'] }}</div></div>
                <div><div style="{{ $muted }}">Unaccounted (1 − invested − cash)</div><div>{{ $basis['unaccounted'] }}</div></div>
                <div><div style="{{ $muted }}">Positions / copied-trader entries (not modelled)</div><div>{{ $snapshot['position_count'] }} / {{ $snapshot['social_trades_count'] }}</div></div>
            </div>

            @if ($warnings !== [])
                <ul style="margin-top: 0.75rem; font-size: 0.8125rem; color: var(--warning-600); list-style: disc; padding-left: 1.25rem;">
                    @foreach ($warnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            @endif

            <h3 style="margin-top: 1.25rem; font-weight: 600; font-size: 0.875rem;">Positions</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; font-size: 0.8125rem; font-variant-numeric: tabular-nums; border-collapse: collapse;">
                    <thead>
                        <tr style="opacity: 0.7;">
                            <th style="{{ $cell }}">#</th>
                            <th style="{{ $cell }}">Instrument</th>
                            <th style="{{ $cell }}">Asset class</th>
                            <th style="{{ $cell }}">Side</th>
                            <th style="{{ $num }}">Weight (whole portfolio)</th>
                            <th style="{{ $num }}">Leverage</th>
                            <th style="{{ $cell }}">Opened</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($positions as $position)
                            <tr style="{{ $row }}">
                                <td style="{{ $cell }}">{{ $position['index'] }}</td>
                                <td style="{{ $cell }}">{{ $position['instrument'] }}</td>
                                <td style="{{ $cell }}">{{ $position['asset_class'] }}</td>
                                <td style="{{ $cell }}">{{ $position['side'] }}</td>
                                <td style="{{ $num }}">{{ $position['weight'] }}</td>
                                <td style="{{ $num }}">{{ $position['leverage'] }}</td>
                                <td style="{{ $cell }} white-space: nowrap;">{{ $position['opened_at'] }}</td>
                            </tr>
                        @empty
                            <tr style="{{ $row }}"><td colspan="7" style="{{ $cell }} opacity: 0.7;">The snapshot has no open positions.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <h3 style="margin-top: 1.25rem; font-weight: 600; font-size: 0.875rem;">Concentration (invested-only basis)</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; font-size: 0.8125rem; font-variant-numeric: tabular-nums; border-collapse: collapse;">
                    <thead>
                        <tr style="opacity: 0.7;">
                            <th style="{{ $cell }}">Dimension</th>
                            <th style="{{ $cell }}">Status</th>
                            <th style="{{ $num }}">HHI</th>
                            <th style="{{ $num }}">Effective positions</th>
                            <th style="{{ $cell }}">Largest</th>
                            <th style="{{ $num }}">Top 3</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dimensions as $dimension)
                            <tr style="{{ $row }}">
                                <td style="{{ $cell }} font-weight: 600;">{{ $dimension['label'] }}</td>
                                <td style="{{ $cell }}"><x-filament::badge :color="$dimension['status_color']">{{ $dimension['status'] }}</x-filament::badge></td>
                                <td style="{{ $num }}">{{ $dimension['hhi'] }}</td>
                                <td style="{{ $num }}">{{ $dimension['effective'] }}</td>
                                <td style="{{ $cell }}">{{ $dimension['largest'] }}</td>
                                <td style="{{ $num }}">{{ $dimension['top_three'] }}</td>
                            </tr>
                            @if ($dimension['note'] !== null)
                                <tr>
                                    <td></td>
                                    <td colspan="5" style="{{ $cell }} {{ $muted }} padding-top: 0;">{{ $dimension['note'] }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($assetClasses !== [])
                <p style="margin-top: 0.5rem; {{ $muted }}">
                    Asset classes:
                    @foreach ($assetClasses as $group)
                        {{ $group['label'] }} {{ $group['weight'] }} ({{ $group['positions'] }})@if (! $loop->last) · @endif
                    @endforeach
                </p>
            @endif

            <h3 style="margin-top: 1.25rem; font-weight: 600; font-size: 0.875rem; display: flex; gap: 0.5rem; align-items: center;">
                Leverage exposure (invested-only basis)
                <x-filament::badge :color="$leverage['status_color']">{{ $leverage['status'] }}</x-filament::badge>
            </h3>
            <table style="width: 100%; font-size: 0.8125rem; font-variant-numeric: tabular-nums; border-collapse: collapse;">
                <tbody>
                    @foreach ($leverage['rows'] as $leverageRow)
                        <tr style="{{ $row }}">
                            <td style="{{ $cell }}">{{ $leverageRow['label'] }}</td>
                            <td style="{{ $num }}">{{ $leverageRow['value'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p style="margin-top: 0.5rem; {{ $muted }}">Unknown leverage is never assumed to be 1x. HHI is shown as a percentage (Σwᵢ²); effective positions = 1 ÷ HHI.</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
