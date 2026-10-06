{{--
    Inline styles on purpose (D-036): the admin panel has no custom Filament
    theme, so arbitrary Tailwind utilities are not compiled.
--}}
@php
    $cell = 'padding: 0.375rem 0.5rem; text-align: left; vertical-align: top;';
    $num = 'padding: 0.375rem 0.5rem; text-align: right; vertical-align: top; white-space: nowrap;';
    $muted = 'font-size: 0.75rem; opacity: 0.7;';
    $row = 'border-top: 1px solid rgba(127, 127, 127, 0.2);';
    $label = 'display: block; font-size: 0.75rem; font-weight: 500; margin-bottom: 0.25rem;';
    $error = 'margin-top: 0.25rem; font-size: 0.75rem; color: var(--danger-600);';
    $heading = 'margin-top: 1.25rem; font-weight: 600; font-size: 0.875rem;';
    $outOfRange = 'Out of range — practically unreachable';
@endphp
<x-filament-widgets::widget>
    <x-filament::section heading="Copy Amount Simulator" description="Simulates copying this trader with a given amount over the latest STORED portfolio snapshot — no eToro request is made. A position is copied when amount × its weight reaches the minimum position amount (§12.1). Weights are shares of the whole portfolio; coverage is relative to the invested weight (Σ positive position weights, cash excluded; D-022).">
        @if (! $available)
            <p style="font-size: 0.875rem; opacity: 0.7;">The simulator needs a stored, visible portfolio snapshot — see the Portfolio section above.</p>
        @else
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); gap: 1rem; align-items: start;">
                <div>
                    <label for="simulator-amount" style="{{ $label }}">Copy amount (USD)</label>
                    <x-filament::input.wrapper prefix="$" :valid="! $errors->has('amount')">
                        <x-filament::input id="simulator-amount" type="text" inputmode="decimal" wire:model.live.debounce.400ms="amount" />
                    </x-filament::input.wrapper>
                    <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                        @foreach ($presets as $preset)
                            <x-filament::button size="xs" color="gray" wire:click="selectPreset({{ $preset['cents'] }})">{{ $preset['label'] }}</x-filament::button>
                        @endforeach
                    </div>
                    @error('amount') <p style="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="simulator-minimum" style="{{ $label }}">Minimum position amount (USD)</label>
                    <x-filament::input.wrapper prefix="$" :valid="! $errors->has('minimumPositionAmount')">
                        <x-filament::input id="simulator-minimum" type="text" inputmode="decimal" wire:model.live.debounce.400ms="minimumPositionAmount" />
                    </x-filament::input.wrapper>
                    <p style="margin-top: 0.25rem; {{ $muted }}">Smallest amount a copied position may have (default $1).</p>
                    @error('minimumPositionAmount') <p style="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="simulator-target" style="{{ $label }}">Target coverage (%, optional)</label>
                    <x-filament::input.wrapper suffix="%" :valid="! $errors->has('targetCoverage')">
                        <x-filament::input id="simulator-target" type="text" inputmode="decimal" placeholder="e.g. 95" wire:model.live.debounce.400ms="targetCoverage" />
                    </x-filament::input.wrapper>
                    <p style="margin-top: 0.25rem; {{ $muted }}">Percentage points of the invested weight, saved with the simulation.</p>
                    @error('targetCoverage') <p style="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            </div>

            @if ($simulation !== null)
                <div style="margin-top: 1.25rem; display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                    @if ($simulation['is_estimate'])
                        <x-filament::badge color="warning">Estimate — see warnings</x-filament::badge>
                    @else
                        <x-filament::badge color="success">Complete snapshot data</x-filament::badge>
                    @endif
                    {{ $this->saveSimulationAction }}
                </div>

                <div style="margin-top: 0.75rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 0.75rem; font-size: 0.875rem;">
                    <div><div style="{{ $muted }}">Copied (eligible)</div><div>{{ $simulation['eligible'] }}</div></div>
                    <div><div style="{{ $muted }}">Skipped</div><div>{{ $simulation['skipped'] }}</div></div>
                    <div><div style="{{ $muted }}">Coverage of invested weight</div><div style="font-weight: 600;">{{ $simulation['coverage'] }}</div></div>
                    <div><div style="{{ $muted }}">Cash (not copied as a position)</div><div>{{ $simulation['cash'] }}</div></div>
                    <div><div style="{{ $muted }}">Unaccounted weight</div><div>{{ $simulation['unknown'] }}</div></div>
                    @if ($simulation['target'] !== null)
                        <div><div style="{{ $muted }}">Minimum amount for {{ $simulation['target']['label'] }} target</div><div>{{ $simulation['target']['minimum'] }}</div></div>
                    @endif
                </div>

                @if ($simulation['warnings'] !== [])
                    <ul style="margin-top: 0.75rem; font-size: 0.8125rem; color: var(--warning-600); list-style: disc; padding-left: 1.25rem;">
                        @foreach ($simulation['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                @endif

                <h3 style="{{ $heading }}">Skipped positions ({{ count($simulation['skipped_positions']) }})</h3>
                @if ($simulation['skipped_positions'] === [])
                    <p style="font-size: 0.875rem; opacity: 0.7;">Every position is copied at this amount.</p>
                @else
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; font-size: 0.8125rem; font-variant-numeric: tabular-nums; border-collapse: collapse;">
                            <thead>
                                <tr style="opacity: 0.7;">
                                    <th style="{{ $cell }}">Instrument</th>
                                    <th style="{{ $num }}">Weight</th>
                                    <th style="{{ $num }}">Estimated amount</th>
                                    <th style="{{ $num }}">Copied from</th>
                                    <th style="{{ $cell }}">Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($simulation['skipped_positions'] as $position)
                                    <tr style="{{ $row }}">
                                        <td style="{{ $cell }}">{{ $position['instrument'] }}</td>
                                        <td style="{{ $num }}">{{ $position['weight'] }}</td>
                                        <td style="{{ $num }}">{{ $position['estimated'] }}</td>
                                        <td style="{{ $num }}">{{ $position['copied_from'] }}</td>
                                        <td style="{{ $cell }}">
                                            <div style="font-weight: 500;">{{ $position['reason'] }}</div>
                                            <div style="{{ $muted }}">{{ $position['explanation'] }}</div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif

            @if ($simulation_out_of_range)
                <p style="margin-top: 1.25rem; font-size: 0.875rem; color: var(--warning-600);">{{ $outOfRange }} — a result for these inputs exceeds the representable amount, so nothing can be simulated or saved.</p>
            @endif

            @if ($matrix !== null)
                <h3 style="{{ $heading }}">Presets (minimum position {{ $matrix['minimum_position'] }})</h3>
                <table style="width: 100%; font-size: 0.8125rem; font-variant-numeric: tabular-nums; border-collapse: collapse;">
                    <thead>
                        <tr style="opacity: 0.7;">
                            <th style="{{ $cell }}">Copy amount</th>
                            <th style="{{ $num }}">Eligible (count / weight)</th>
                            <th style="{{ $num }}">Skipped (count / weight)</th>
                            <th style="{{ $num }}">Coverage of invested weight</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($matrix['presets'] as $preset)
                            <tr style="{{ $row }}">
                                <td style="{{ $cell }} font-weight: 600;">{{ $preset['label'] }}</td>
                                @if ($preset['out_of_range'])
                                    <td colspan="3" style="{{ $num }} color: var(--warning-600);">{{ $outOfRange }}</td>
                                @else
                                    <td style="{{ $num }}">{{ $preset['eligible'] }}</td>
                                    <td style="{{ $num }}">{{ $preset['skipped'] }}</td>
                                    <td style="{{ $num }}">{{ $preset['coverage'] }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <h3 style="{{ $heading }}">Minimum copy amount per coverage target</h3>
                <table style="width: 100%; font-size: 0.8125rem; font-variant-numeric: tabular-nums; border-collapse: collapse;">
                    <thead>
                        <tr style="opacity: 0.7;">
                            <th style="{{ $cell }}">Target</th>
                            <th style="{{ $num }}">Minimum amount (≥ {{ $matrix['platform_minimum'] }} platform minimum)</th>
                            <th style="{{ $num }}">Mathematical minimum</th>
                            <th style="{{ $num }}">Coverage reached</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($matrix['targets'] as $target)
                            <tr style="{{ $row }} @if ($target['informational']) opacity: 0.75; @endif">
                                <td style="{{ $cell }} font-weight: 600;">{{ $target['label'] }}{{ $target['informational'] ? '*' : '' }}</td>
                                @if ($target['out_of_range'])
                                    <td colspan="3" style="{{ $num }} color: var(--warning-600);">{{ $outOfRange }}</td>
                                @elseif ($target['reachable'])
                                    <td style="{{ $num }}">{{ $target['minimum'] }}</td>
                                    <td style="{{ $num }}">{{ $target['mathematical'] }}</td>
                                    <td style="{{ $num }}">{{ $target['achieved'] }}</td>
                                @else
                                    <td colspan="3" style="{{ $num }} color: var(--warning-600);">Not reachable — no position has a positive weight</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p style="margin-top: 0.5rem; {{ $muted }}">
                    * 100% = every visible position; informational only — tiny positions can make it economically meaningless (§12.3).
                    @if ($matrix['is_estimate']) Snapshot data is incomplete, so every figure is an estimate. @endif
                    @if ($matrix['out_of_range']) “Out of range” = the amount exceeds the representable range; for this snapshot and minimum position amount it is practically unreachable. @endif
                </p>
            @endif
        @endif

        <h3 style="{{ $heading }}">Saved simulations (latest {{ count($saved) }})</h3>
        @if ($saved === [])
            <p style="font-size: 0.875rem; opacity: 0.7;">No simulation saved for this trader yet.</p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; font-size: 0.8125rem; font-variant-numeric: tabular-nums; border-collapse: collapse;">
                    <thead>
                        <tr style="opacity: 0.7;">
                            <th style="{{ $cell }}">Calculated</th>
                            <th style="{{ $cell }}">Snapshot</th>
                            <th style="{{ $num }}">Amount</th>
                            <th style="{{ $num }}">Min. position</th>
                            <th style="{{ $num }}">Target → minimum</th>
                            <th style="{{ $num }}">Eligible / skipped</th>
                            <th style="{{ $num }}">Coverage</th>
                            <th style="{{ $cell }}">Methodology</th>
                            <th style="{{ $cell }}">Reproduces from snapshot</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($saved as $simulationRow)
                            <tr style="{{ $row }}">
                                <td style="{{ $cell }} white-space: nowrap;">{{ $simulationRow['calculated_at'] }}</td>
                                <td style="{{ $cell }}">{{ $simulationRow['snapshot'] }}</td>
                                <td style="{{ $num }}">{{ $simulationRow['amount'] }}</td>
                                <td style="{{ $num }}">{{ $simulationRow['minimum_position'] }}</td>
                                <td style="{{ $num }}">{{ $simulationRow['target'] }}</td>
                                <td style="{{ $num }}">{{ $simulationRow['counts'] }}</td>
                                <td style="{{ $num }}">{{ $simulationRow['coverage'] }}@if ($simulationRow['is_estimate']) (estimate)@endif</td>
                                <td style="{{ $cell }} white-space: nowrap;">{{ $simulationRow['methodology'] }}</td>
                                <td style="{{ $cell }}">{{ $simulationRow['reproduces'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
