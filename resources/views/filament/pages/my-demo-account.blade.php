{{--
    Inline styles on purpose (D-036): the admin panel has no custom Filament
    theme, so arbitrary Tailwind utilities are not compiled.
--}}
@php
    $cell = 'padding: 0.5rem 0.625rem; text-align: left; vertical-align: top; white-space: nowrap;';
    $num = 'padding: 0.5rem 0.625rem; text-align: right; vertical-align: top; white-space: nowrap; font-variant-numeric: tabular-nums;';
    $muted = 'font-size: 0.75rem; opacity: 0.7;';
    $row = 'border-top: 1px solid rgba(127, 127, 127, 0.2);';
    $statLabel = 'font-size: 0.75rem; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.03em;';
    $statValue = 'font-size: 1.375rem; font-weight: 600; font-variant-numeric: tabular-nums;';
@endphp
<x-filament-panels::page>
    <x-filament::callout
        color="warning"
        icon="heroicon-o-beaker"
        heading="DEMO account — virtual money"
        description="Read-only snapshots of the eToro DEMO account (no real funds). Real account tracking stays disabled until the Demo tracking is accepted. All times are Europe/Malta."
        data-demo-account-notice
    />

    @if ($summary === null)
        <x-filament::section heading="No snapshot yet" data-demo-account-empty>
            <p style="font-size: 0.875rem;">The DEMO account has not been synced yet. Use “Sync demo account” (needs a running queue worker) or run <code>php artisan etoro:sync-account --demo --now</code>.</p>
            @if ($lastRun)
                <p style="{{ $muted }} margin-top: 0.5rem;">Last sync attempt: {{ $lastRun['status'] }} at {{ $lastRun['finished'] }}@if ($lastRun['error']) — {{ $lastRun['error'] }}@endif</p>
            @endif
        </x-filament::section>
    @else
        <x-filament::section heading="Latest snapshot" :description="'Captured '.$summary['capturedAt'].' · last confirmed unchanged '.$summary['confirmedAt']" data-demo-account-summary>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 1rem;">
                <div>
                    <div style="{{ $statLabel }}">Credit (available balance)</div>
                    <div style="{{ $statValue }}">{{ $summary['credit'] }}</div>
                </div>
                <div>
                    <div style="{{ $statLabel }}">Invested</div>
                    <div style="{{ $statValue }}">{{ $summary['invested'] }}</div>
                </div>
                <div>
                    <div style="{{ $statLabel }}">Unrealized P&amp;L</div>
                    <div style="{{ $statValue }}">{{ $summary['unrealized'] }}</div>
                </div>
                <div>
                    <div style="{{ $statLabel }}">Equity</div>
                    <div style="{{ $statValue }}">{{ $summary['equity'] }}</div>
                    @if ($summary['equityNote'])
                        <div style="{{ $muted }}">{{ $summary['equityNote'] }}</div>
                    @endif
                </div>
            </div>
            <p style="{{ $muted }} margin-top: 0.75rem;">
                {{ $summary['positionCount'] }} open position(s) · {{ $summary['mirrorCount'] }} copy(ies) · {{ $summary['pendingOrderCount'] }} pending order(s)@if ($summary['bonus']) · bonus credit {{ $summary['bonus'] }}@endif.
                Invested and equity follow eToro's “Calculate Total Invested” / “Calculate Equity” guides; unrealized P&amp;L is eToro's own figure.
            </p>
            @if ($lastRun)
                <p style="{{ $muted }}">Last sync attempt: {{ $lastRun['status'] }} at {{ $lastRun['finished'] }}@if ($lastRun['error']) — {{ $lastRun['error'] }}@endif</p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Open positions" data-demo-account-positions>
            @if ($positions === [])
                <p style="font-size: 0.875rem; opacity: 0.7;">No open positions.</p>
            @else
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                        <thead>
                            <tr>
                                <th style="{{ $cell }}">Instrument</th>
                                <th style="{{ $cell }}">Direction</th>
                                <th style="{{ $num }}">Amount</th>
                                <th style="{{ $num }}">Initial amount</th>
                                <th style="{{ $num }}">Units</th>
                                <th style="{{ $num }}">Open rate</th>
                                <th style="{{ $cell }}">Opened</th>
                                <th style="{{ $num }}">Leverage</th>
                                <th style="{{ $num }}">Unrealized P&amp;L</th>
                                <th style="{{ $cell }}">Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($positions as $position)
                                <tr style="{{ $row }}">
                                    <td style="{{ $cell }}">
                                        {{ $position['instrument'] }}
                                        @if ($position['instrumentName'])
                                            <div style="{{ $muted }}">{{ $position['instrumentName'] }}</div>
                                        @endif
                                    </td>
                                    <td style="{{ $cell }}">{{ $position['direction'] }}</td>
                                    <td style="{{ $num }}">{{ $position['amount'] }}</td>
                                    <td style="{{ $num }}">{{ $position['initialAmount'] }}</td>
                                    <td style="{{ $num }}">{{ $position['units'] }}</td>
                                    <td style="{{ $num }}">{{ $position['openRate'] }}</td>
                                    <td style="{{ $cell }}">{{ $position['openedAt'] }}</td>
                                    <td style="{{ $num }}">{{ $position['leverage'] }}</td>
                                    <td style="{{ $num }}">{{ $position['pnl'] }}</td>
                                    <td style="{{ $cell }}">{{ $position['copy'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Copies (eToro mirrors)" data-demo-account-mirrors>
            @if ($mirrors === [])
                <p style="font-size: 0.875rem; opacity: 0.7;">No active copies on the DEMO account.</p>
            @else
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                        <thead>
                            <tr>
                                <th style="{{ $cell }}">Trader</th>
                                <th style="{{ $cell }}">Status</th>
                                <th style="{{ $num }}">Initial investment</th>
                                <th style="{{ $num }}">Net added</th>
                                <th style="{{ $num }}">In open positions</th>
                                <th style="{{ $num }}">Available in copy</th>
                                <th style="{{ $num }}">Unrealized P&amp;L</th>
                                <th style="{{ $num }}">Realized (closed)</th>
                                <th style="{{ $num }}">Positions</th>
                                <th style="{{ $cell }}">Started</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($mirrors as $mirror)
                                <tr style="{{ $row }}">
                                    <td style="{{ $cell }}">
                                        @if ($mirror['traderUrl'])
                                            <a href="{{ $mirror['traderUrl'] }}" style="text-decoration: underline;">{{ $mirror['trader'] }}</a>
                                        @else
                                            {{ $mirror['trader'] }}
                                        @endif
                                        <div style="{{ $muted }}">CID {{ $mirror['cid'] }}</div>
                                    </td>
                                    <td style="{{ $cell }}">{{ $mirror['status'] }}</td>
                                    <td style="{{ $num }}">{{ $mirror['initialInvestment'] }}</td>
                                    <td style="{{ $num }}">{{ $mirror['netDeposits'] }}</td>
                                    <td style="{{ $num }}">{{ $mirror['invested'] }}</td>
                                    <td style="{{ $num }}">{{ $mirror['available'] }}</td>
                                    <td style="{{ $num }}">{{ $mirror['unrealized'] }}</td>
                                    <td style="{{ $num }}">{{ $mirror['realized'] }}</td>
                                    <td style="{{ $num }}">{{ $mirror['positions'] }}</td>
                                    <td style="{{ $cell }}">{{ $mirror['startedAt'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Snapshot history" :description="'Latest '.\App\Filament\Pages\MyDemoAccount::HISTORY_ROWS.' snapshots; a new one is stored only when the account changed.'" data-demo-account-history>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr>
                            <th style="{{ $cell }}">Captured</th>
                            <th style="{{ $cell }}">Last confirmed</th>
                            <th style="{{ $num }}">Credit</th>
                            <th style="{{ $num }}">Invested</th>
                            <th style="{{ $num }}">Unrealized P&amp;L</th>
                            <th style="{{ $num }}">Equity</th>
                            <th style="{{ $num }}">Positions</th>
                            <th style="{{ $num }}">Copies</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $snapshot)
                            <tr style="{{ $row }}">
                                <td style="{{ $cell }}">{{ $snapshot['capturedAt'] }}</td>
                                <td style="{{ $cell }}">{{ $snapshot['confirmedAt'] }}</td>
                                <td style="{{ $num }}">{{ $snapshot['credit'] }}</td>
                                <td style="{{ $num }}">{{ $snapshot['invested'] }}</td>
                                <td style="{{ $num }}">{{ $snapshot['unrealized'] }}</td>
                                <td style="{{ $num }}">{{ $snapshot['equity'] }}</td>
                                <td style="{{ $num }}">{{ $snapshot['positions'] }}</td>
                                <td style="{{ $num }}">{{ $snapshot['mirrors'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
