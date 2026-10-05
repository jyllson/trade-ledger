{{--
    Inline styles on purpose: the admin panel has no custom Filament theme,
    so arbitrary Tailwind utilities are not compiled. Colors use Filament's
    own CSS variables, so light/dark mode still follow the panel.
--}}
<x-filament-widgets::widget>
    <x-filament::section heading="Monthly returns" description="Decimal-fraction gains shown as percent, per calendar month. Partial and in-progress months are marked.">
        @if ($rows === [])
            <p style="font-size: 0.875rem; opacity: 0.7;">No monthly performance stored yet. Use “Sync performance”.</p>
        @else
            <div style="overflow-x: auto;">
                <table style="width: 100%; font-size: 0.75rem; font-variant-numeric: tabular-nums; border-collapse: collapse;">
                    <thead>
                        <tr style="opacity: 0.7;">
                            <th style="padding: 0.25rem 0.5rem; text-align: left;">Year</th>
                            @foreach (['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as $month)
                                <th style="padding: 0.25rem 0.5rem; text-align: right;">{{ $month }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-top: 1px solid var(--gray-200);">
                                <td style="padding: 0.25rem 0.5rem; font-weight: 600;">{{ $row['year'] }}</td>
                                @foreach ($row['months'] as $cell)
                                    @php
                                        $style = 'padding: 0.25rem 0.5rem; text-align: right;';
                                        if ($cell !== null && $cell['sign'] > 0) {
                                            $style .= ' color: var(--success-600);';
                                        } elseif ($cell !== null && $cell['sign'] < 0) {
                                            $style .= ' color: var(--danger-600);';
                                        }
                                        if ($cell !== null && $cell['note'] !== null) {
                                            $style .= ' font-style: italic; opacity: 0.7;';
                                        }
                                    @endphp
                                    <td style="{{ $style }}" @if ($cell !== null && $cell['note'] !== null) title="{{ $cell['note'] }}" @endif>
                                        {{ $cell['label'] ?? '' }}@if ($cell !== null && $cell['note'] !== null)*@endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p style="margin-top: 0.5rem; font-size: 0.75rem; opacity: 0.7;">* partial first month or month in progress — excluded from per-month statistics.</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
