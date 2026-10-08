<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Application\Account\AccountValuation;
use App\Application\Account\QueueEtoroAccountSync;
use App\Application\Account\SyncEtoroAccount;
use App\Etoro\EtoroEnvironment;
use App\Filament\Resources\Traders\TraderResource;
use App\Filament\Support\DateTimeDisplay;
use App\Filament\Support\NumberDisplay;
use App\Filament\Widgets\Account\DemoAccountHistoryChart;
use App\Models\AccountMirror;
use App\Models\AccountPosition;
use App\Models\AccountSnapshot;
use App\Models\ImportRun;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Read-only view of the own eToro DEMO account (PROJECT.md §20 M6,
 * docs/DECISIONS.md D-051): latest stored snapshot, its positions and
 * copies, and the snapshot history. Rendering never calls eToro; the only
 * action queues SyncEtoroAccountJob. Times in Europe/Malta (D-046).
 */
class MyDemoAccount extends Page
{
    protected string $view = 'filament.pages.my-demo-account';

    protected static ?string $navigationLabel = 'My demo account';

    protected static ?string $title = 'My demo account';

    protected static ?string $slug = 'my-demo-account';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Demo trading';

    protected static ?int $navigationSort = 1;

    public const HISTORY_ROWS = 20;

    private const EQUITY_REASONS = [
        AccountValuation::REASON_ORDERS_UNKNOWN => 'pending orders are not fully reported in the payload',
        AccountValuation::REASON_POSITION_PNL_MISSING => 'eToro did not report P&L for every position',
        AccountValuation::REASON_MIRROR_FIELDS_MISSING => 'a copy is missing its available amount, closed profit or positions',
    ];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncDemoAccount')
                ->label('Sync demo account')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (QueueEtoroAccountSync $queueEtoroAccountSync): void {
                    if (! $queueEtoroAccountSync->handle(EtoroEnvironment::Demo)) {
                        Notification::make()
                            ->title('eToro integration is disabled — nothing was queued.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Demo account sync queued')
                        ->body('A new snapshot is stored when the queue worker runs the job (only if the account changed). Reload the page afterwards.')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [DemoAccountHistoryChart::class];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $latest = AccountSnapshot::query()
            ->where('environment', EtoroEnvironment::Demo)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();

        $lastRun = ImportRun::query()
            ->where('type', SyncEtoroAccount::TYPE)
            ->where('metadata->query->environment', EtoroEnvironment::Demo->value)
            ->orderByDesc('id')
            ->first();

        return [
            'summary' => $latest === null ? null : $this->summary($latest),
            'positions' => $latest === null ? [] : $this->positionRows($latest),
            'mirrors' => $latest === null ? [] : $this->mirrorRows($latest),
            'history' => $this->historyRows(),
            'lastRun' => $lastRun === null ? null : [
                'status' => $lastRun->status->value,
                'finished' => DateTimeDisplay::format($lastRun->finished_at ?? $lastRun->started_at),
                'error' => $lastRun->error_summary,
            ],
        ];
    }

    /**
     * @return array<string, string|int|null>
     */
    private function summary(AccountSnapshot $snapshot): array
    {
        return [
            'credit' => NumberDisplay::usd($snapshot->credit_cents),
            'invested' => NumberDisplay::usd($snapshot->invested_cents),
            'unrealized' => NumberDisplay::usd($snapshot->unrealized_pnl_cents),
            'equity' => NumberDisplay::usd($snapshot->equity_cents),
            'equityNote' => $snapshot->equity_cents === null
                ? 'Not shown: '.(self::EQUITY_REASONS[$snapshot->equity_unavailable_reason] ?? 'not computable from the payload').'.'
                : null,
            'bonus' => $snapshot->bonus_credit_cents === null ? null : NumberDisplay::usd($snapshot->bonus_credit_cents),
            'capturedAt' => DateTimeDisplay::format($snapshot->captured_at),
            'confirmedAt' => DateTimeDisplay::format($snapshot->last_confirmed_at),
            'positionCount' => $snapshot->position_count + $snapshot->mirror_position_count,
            'mirrorCount' => $snapshot->mirror_count,
            'pendingOrderCount' => $snapshot->pending_order_count,
        ];
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function positionRows(AccountSnapshot $snapshot): array
    {
        return array_values($snapshot->positions()->with(['instrument', 'mirror'])->get()->map(fn (AccountPosition $position): array => [
            'instrument' => $position->instrument->symbol ?? 'Instrument #'.$position->external_instrument_id,
            'instrumentName' => $position->instrument?->name,
            'direction' => $position->is_buy ? 'Buy' : 'Sell',
            'amount' => NumberDisplay::usd($position->amount_cents),
            'initialAmount' => NumberDisplay::usd($position->initial_amount_cents),
            'units' => NumberDisplay::decimal($position->units, 4),
            'openRate' => NumberDisplay::decimal($position->open_rate, 4),
            'openedAt' => DateTimeDisplay::format($position->opened_at),
            'leverage' => $position->leverage === null ? '—' : '×'.$position->leverage,
            'pnl' => NumberDisplay::usd($position->pnl_cents),
            'copy' => match (true) {
                $position->mirror !== null => 'Copy of '.($position->mirror->parent_username ?? 'CID '.$position->mirror->parent_cid),
                $position->external_mirror_id !== null => 'Copy (mirror '.$position->external_mirror_id.')',
                default => 'Manual',
            },
        ])->all());
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function mirrorRows(AccountSnapshot $snapshot): array
    {
        return array_values($snapshot->mirrors()->with('trader')->get()->map(fn (AccountMirror $mirror): array => [
            'trader' => $mirror->trader->username ?? $mirror->parent_username ?? 'CID '.$mirror->parent_cid,
            'traderUrl' => $mirror->trader === null ? null : TraderResource::getUrl('view', ['record' => $mirror->trader]),
            'cid' => $mirror->parent_cid,
            'initialInvestment' => NumberDisplay::usd($mirror->initial_investment_cents),
            'netDeposits' => $mirror->deposit_summary_cents === null || $mirror->withdrawal_summary_cents === null
                ? '—'
                : NumberDisplay::usd($mirror->deposit_summary_cents - $mirror->withdrawal_summary_cents),
            'invested' => NumberDisplay::usd($mirror->invested_cents),
            'available' => NumberDisplay::usd($mirror->available_amount_cents),
            'unrealized' => NumberDisplay::usd($mirror->unrealized_pnl_cents),
            'realized' => NumberDisplay::usd($mirror->closed_positions_net_profit_cents),
            'positions' => $mirror->position_count === null ? '—' : (string) $mirror->position_count,
            'startedAt' => DateTimeDisplay::format($mirror->started_at),
            'status' => $mirror->statusLabel() ?? ($mirror->is_paused === true ? 'Paused' : '—'),
        ])->all());
    }

    /**
     * @return list<array<string, string|int>>
     */
    private function historyRows(): array
    {
        return array_values(AccountSnapshot::query()
            ->where('environment', EtoroEnvironment::Demo)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY_ROWS)
            ->get()
            ->map(fn (AccountSnapshot $snapshot): array => [
                'capturedAt' => DateTimeDisplay::format($snapshot->captured_at),
                'confirmedAt' => DateTimeDisplay::format($snapshot->last_confirmed_at),
                'credit' => NumberDisplay::usd($snapshot->credit_cents),
                'invested' => NumberDisplay::usd($snapshot->invested_cents),
                'unrealized' => NumberDisplay::usd($snapshot->unrealized_pnl_cents),
                'equity' => NumberDisplay::usd($snapshot->equity_cents),
                'positions' => $snapshot->position_count + $snapshot->mirror_position_count,
                'mirrors' => $snapshot->mirror_count,
            ])->all());
    }
}
