<?php

declare(strict_types=1);

namespace App\Filament\Resources\Traders\Actions;

use App\Application\DemoCopy\AdjustDemoCopy;
use App\Application\DemoCopy\CloseDemoCopy;
use App\Application\DemoCopy\DemoCopyAmount;
use App\Application\DemoCopy\DemoCopyAvailability;
use App\Application\DemoCopy\DemoCopyLedger;
use App\Application\DemoCopy\DemoCopyRefused;
use App\Application\DemoCopy\PreCheckDemoCopy;
use App\Application\DemoCopy\StartDemoCopy;
use App\Etoro\DemoCopyUnregisterType;
use App\Filament\Resources\DemoCopyOperations\DemoCopyOperationResource;
use App\Filament\Resources\Traders\Pages\ViewTrader;
use App\Models\DemoCopyOperation;
use App\Models\DemoCopyOperationStatus;
use App\Models\DemoCopyOperationType;
use App\Models\Trader;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Trader-page actions for copying on the eToro DEMO account (D-050).
 * Every eToro call goes through the App\Application\DemoCopy use cases;
 * this class only collects input and shows results.
 *
 * Flow: amount form → eToro pre-check (audited) → if allowed, a separate
 * confirmation modal (DEMO account, virtual money, trader, amount,
 * pre-check verdict, mandatory checkbox) → start/adjust → status. Close has
 * no documented pre-check, so it goes straight to its own confirmation.
 * While demo copy is off, the entry actions stay visible but disabled with
 * the reason as tooltip, and the confirmation actions cannot be mounted.
 */
final class DemoCopyActions
{
    public const string START = 'copyOnDemo';

    public const string CONFIRM_START = 'confirmDemoCopy';

    public const string ADJUST = 'adjustDemoCopy';

    public const string CONFIRM_ADJUST = 'confirmAdjustDemoCopy';

    public const string CLOSE = 'closeDemoCopy';

    public static function start(): Action
    {
        return self::preCheckAction(self::START, DemoCopyOperationType::Start, self::CONFIRM_START)
            ->label('Copy on demo')
            ->icon(Heroicon::OutlinedBeaker)
            ->modalHeading('Copy this trader on the eToro DEMO account')
            ->modalDescription(fn (Trader $record): HtmlString => new HtmlString(implode('<br>', array_filter([
                e('Step 1 of 2: eToro pre-check (a dry run that changes nothing). DEMO account only — virtual money. Nothing is copied until you confirm in step 2.'),
                self::unconfirmedCloseWarning($record),
            ]))))
            ->disabled(fn (Trader $record): bool => self::disabledReason($record, DemoCopyOperationType::Start) !== null)
            ->tooltip(fn (Trader $record): ?string => self::disabledReason($record, DemoCopyOperationType::Start));
    }

    public static function adjust(): Action
    {
        return self::preCheckAction(self::ADJUST, DemoCopyOperationType::Adjust, self::CONFIRM_ADJUST)
            ->label('Adjust demo copy')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->modalHeading('Add or remove funds of the DEMO copy')
            ->modalDescription('Step 1 of 2: eToro pre-check. A positive amount adds virtual funds, a negative amount removes them; eToro then realigns the copied positions. DEMO account only.')
            ->disabled(fn (Trader $record): bool => self::disabledReason($record, DemoCopyOperationType::Adjust) !== null)
            ->tooltip(fn (Trader $record): ?string => self::disabledReason($record, DemoCopyOperationType::Adjust));
    }

    public static function confirmStart(): Action
    {
        return self::confirmAction(self::CONFIRM_START, DemoCopyOperationType::Start);
    }

    public static function confirmAdjust(): Action
    {
        return self::confirmAction(self::CONFIRM_ADJUST, DemoCopyOperationType::Adjust);
    }

    public static function close(): Action
    {
        return Action::make(self::CLOSE)
            ->label('Close demo copy')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->disabled(fn (Trader $record): bool => self::disabledReason($record, DemoCopyOperationType::Close) !== null)
            ->tooltip(fn (Trader $record): ?string => self::disabledReason($record, DemoCopyOperationType::Close))
            ->modalHeading('Close the DEMO copy of this trader')
            ->modalDescription(fn (Trader $record): HtmlString => self::closeDescription($record))
            ->modalSubmitActionLabel('Close on DEMO')
            ->schema([
                Select::make('unregister_type')
                    ->label('How')
                    ->options([
                        DemoCopyUnregisterType::Close->value => 'Close — liquidate the copied positions and return the funds',
                        DemoCopyUnregisterType::Detach->value => 'Detach — keep the positions as self-managed in the main portfolio',
                    ])
                    ->default(DemoCopyUnregisterType::Close->value)
                    ->required(),
                Checkbox::make('confirm_demo')
                    ->label('I confirm: this closes the copy on my eToro DEMO account (virtual money).')
                    ->accepted()
                    ->required(),
            ])
            ->action(function (Trader $record, array $data, Action $action): void {
                try {
                    $operation = app(CloseDemoCopy::class)->handle(
                        $record,
                        DemoCopyUnregisterType::from((string) $data['unregister_type']),
                        self::userId(),
                        (bool) ($data['confirm_demo'] ?? false),
                    );
                } catch (DemoCopyRefused $refused) {
                    self::notifyRefused($refused);
                    $action->halt();

                    return;
                }

                self::notifyOperation($operation);
            });
    }

    private static function preCheckAction(string $name, DemoCopyOperationType $intent, string $confirmActionName): Action
    {
        return Action::make($name)
            ->color('warning')
            ->modalSubmitActionLabel('Run eToro pre-check')
            ->schema([
                TextInput::make('amount')
                    ->label('Amount (USD, account currency)')
                    ->helperText($intent === DemoCopyOperationType::Start
                        ? 'Positive, up to 2 decimals, at most '.self::maximumLabel().'. eToro decides its own minimum in the pre-check.'
                        : 'Non-zero, up to 2 decimals; negative removes funds. At most '.self::maximumLabel().' either way.')
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($intent): void {
                        $amountCents = is_string($value) || is_int($value) ? DemoCopyAmount::parseUsd((string) $value) : null;

                        if ($amountCents === null) {
                            $fail('Enter a USD amount with at most 2 decimals.');

                            return;
                        }

                        try {
                            DemoCopyAmount::assertValidFor($intent, $amountCents);
                        } catch (DemoCopyRefused $refused) {
                            $fail($refused->getMessage());
                        }
                    }),
            ])
            ->action(function (Trader $record, array $data, Action $action, ViewTrader $livewire) use ($intent, $confirmActionName): void {
                try {
                    $preCheck = app(PreCheckDemoCopy::class)->handle(
                        $record,
                        (int) DemoCopyAmount::parseUsd((string) $data['amount']),
                        $intent,
                        self::userId(),
                    );
                } catch (DemoCopyRefused $refused) {
                    self::notifyRefused($refused);
                    $action->halt();

                    return;
                }

                if ($preCheck->status !== DemoCopyOperationStatus::Accepted) {
                    Notification::make()
                        ->title('eToro pre-check: not allowed — nothing was copied')
                        ->body(($preCheck->reason ?? 'No reason given.').' (status: '.$preCheck->status->value.')')
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $livewire->replaceMountedAction($confirmActionName, ['preCheck' => $preCheck->id]);
            });
    }

    private static function confirmAction(string $name, DemoCopyOperationType $type): Action
    {
        return Action::make($name)
            ->visible(fn (): bool => app(DemoCopyAvailability::class)->isEnabled())
            ->color('danger')
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalHeading($type === DemoCopyOperationType::Start ? 'Confirm: copy on the DEMO account' : 'Confirm: adjust the DEMO copy')
            ->modalDescription(fn (Trader $record, array $arguments): HtmlString => self::confirmDescription($record, self::preCheckFrom($record, $arguments), $type))
            ->modalSubmitActionLabel($type === DemoCopyOperationType::Start ? 'Start copy on DEMO' : 'Adjust copy on DEMO')
            ->schema(fn (Trader $record): array => array_filter([
                Checkbox::make('confirm_demo')
                    ->label('I confirm: this runs on my eToro DEMO account with virtual money, for the trader and amount shown above.')
                    ->accepted()
                    ->required(),
                app(DemoCopyLedger::class)->unresolvedRegistrationCount($record) > 0
                    ? Checkbox::make('acknowledge_unresolved')
                        ->label('An earlier demo copy operation for this trader has an UNKNOWN outcome and may already have executed. I checked eToro and still want to continue.')
                        ->accepted()
                        ->required()
                    : null,
                $type === DemoCopyOperationType::Start && app(DemoCopyLedger::class)->unconfirmedClose($record) !== null
                    ? Checkbox::make('acknowledge_unconfirmed_close')
                        ->label('The earlier close of this copy is NOT confirmed. I verified in eToro that the copy is closed, and I understand that otherwise eToro may treat this start as adding funds to the existing copy.')
                        ->accepted()
                        ->required()
                    : null,
            ]))
            ->action(function (Trader $record, array $data, array $arguments, Action $action) use ($type): void {
                $preCheck = self::preCheckFrom($record, $arguments);

                if ($preCheck === null) {
                    self::notifyRefused(DemoCopyRefused::because('The pre-check was not found for this trader. Start again.'));

                    return;
                }

                try {
                    $operation = $type === DemoCopyOperationType::Start
                        ? app(StartDemoCopy::class)->handle(
                            $preCheck,
                            self::userId(),
                            (bool) ($data['confirm_demo'] ?? false),
                            (bool) ($data['acknowledge_unresolved'] ?? false),
                            (bool) ($data['acknowledge_unconfirmed_close'] ?? false),
                        )
                        : app(AdjustDemoCopy::class)->handle(
                            $preCheck,
                            self::userId(),
                            (bool) ($data['confirm_demo'] ?? false),
                            (bool) ($data['acknowledge_unresolved'] ?? false),
                        );
                } catch (DemoCopyRefused $refused) {
                    self::notifyRefused($refused);
                    $action->halt();

                    return;
                }

                self::notifyOperation($operation);
            });
    }

    /**
     * The pre-check id arrives from Livewire state, so it is re-scoped to
     * the page's trader here; the use case re-validates everything else.
     *
     * @param  array<string, mixed>  $arguments
     */
    private static function preCheckFrom(Trader $record, array $arguments): ?DemoCopyOperation
    {
        $id = $arguments['preCheck'] ?? null;

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        return DemoCopyOperation::query()
            ->whereKey((int) $id)
            ->where('trader_id', $record->id)
            ->where('type', DemoCopyOperationType::PreCheck)
            ->first();
    }

    private static function confirmDescription(Trader $record, ?DemoCopyOperation $preCheck, DemoCopyOperationType $type): HtmlString
    {
        $lines = [
            '<strong>DEMO account — virtual money.</strong> Nothing happens on a real account.',
            'Trader: <strong>'.e($record->username).'</strong> (CID '.e($record->external_cid).')',
            ($type === DemoCopyOperationType::Start ? 'Amount to copy: ' : 'Funds change: ').'<strong>'.e(DemoCopyAmount::format($preCheck?->amount_cents)).'</strong>',
            'eToro pre-check: '.e($preCheck?->status === DemoCopyOperationStatus::Accepted ? 'allowed (point-in-time verdict, not a reservation)' : 'missing or not allowed'),
            'eToro processes the request asynchronously; the outcome is polled and shown in Demo copy operations.',
        ];

        if ($type === DemoCopyOperationType::Start) {
            $lines[] = self::unconfirmedCloseWarning($record);
        }

        return new HtmlString(implode('<br>', array_filter($lines)));
    }

    /**
     * Shown on the start steps while a close of the trader's copy is not
     * confirmed (D-050); null otherwise.
     */
    private static function unconfirmedCloseWarning(Trader $record): ?string
    {
        $unconfirmedClose = app(DemoCopyLedger::class)->unconfirmedClose($record);

        if ($unconfirmedClose === null) {
            return null;
        }

        return '<strong>Warning: the close of the previous copy (mirror '.e((string) $unconfirmedClose->mirror_id).') was requested but is NOT confirmed.</strong> '
            .'If it has not completed, eToro may treat this start as adding funds to the existing copy. Verify in eToro that the copy is closed before continuing.';
    }

    private static function closeDescription(Trader $record): HtmlString
    {
        $activeCopy = app(DemoCopyLedger::class)->activeCopy($record);

        return new HtmlString(implode('<br>', array_filter([
            '<strong>DEMO account — virtual money.</strong> Nothing happens on a real account.',
            'Trader: <strong>'.e($record->username).'</strong> (CID '.e($record->external_cid).')',
            'Copy (mirrorID): <strong>'.e((string) ($activeCopy->mirror_id ?? '—')).'</strong>',
            'eToro documents no pre-check for closing, and only acknowledges the request — completion is never confirmed here and must be verified in eToro. The copy stays recorded as active.',
            app(DemoCopyLedger::class)->unconfirmedClose($record) !== null
                ? '<strong>A close of this copy was already requested and is not confirmed.</strong> Sending it again reuses the same clientRequestID (same eToro operation).'
                : null,
        ])));
    }

    /**
     * Null when the action can be used; otherwise the reason shown as
     * tooltip on the disabled action.
     */
    private static function disabledReason(Trader $record, DemoCopyOperationType $intent): ?string
    {
        $availabilityReason = app(DemoCopyAvailability::class)->disabledReason();

        if ($availabilityReason !== null) {
            return $availabilityReason;
        }

        $ledger = app(DemoCopyLedger::class);
        $activeCopy = $ledger->activeCopy($record);
        $closeUnconfirmed = $ledger->unconfirmedClose($record) !== null;

        return match (true) {
            $intent === DemoCopyOperationType::Start && $activeCopy !== null && ! $closeUnconfirmed => 'Already copied on demo (mirror '.$activeCopy->mirror_id.') — use "Adjust demo copy".',
            $intent !== DemoCopyOperationType::Start && $activeCopy === null => 'No active demo copy of this trader is known to this application.',
            $intent === DemoCopyOperationType::Adjust && $closeUnconfirmed => DemoCopyRefused::CLOSE_UNCONFIRMED_ADJUST,
            default => null,
        };
    }

    private static function notifyOperation(DemoCopyOperation $operation): void
    {
        $notification = Notification::make()
            ->title($operation->type->label().': '.$operation->status->value)
            ->body($operation->reason)
            ->actions([
                Action::make('viewOperations')
                    ->label('Demo copy operations')
                    ->url(DemoCopyOperationResource::getUrl('index')),
            ]);

        match (true) {
            $operation->type === DemoCopyOperationType::Close && $operation->status === DemoCopyOperationStatus::Accepted => $notification->title('Close demo copy: requested — not confirmed')->warning()->persistent(),
            default => self::colorByStatus($notification, $operation->status),
        };

        $notification->send();
    }

    private static function colorByStatus(Notification $notification, DemoCopyOperationStatus $status): Notification
    {
        return match ($status) {
            DemoCopyOperationStatus::Accepted, DemoCopyOperationStatus::Succeeded => $notification->success(),
            DemoCopyOperationStatus::Unknown, DemoCopyOperationStatus::Requested => $notification->warning()->persistent(),
            DemoCopyOperationStatus::Rejected, DemoCopyOperationStatus::Failed => $notification->danger()->persistent(),
        };
    }

    private static function notifyRefused(DemoCopyRefused $refused): void
    {
        Notification::make()
            ->title('Nothing was sent to eToro')
            ->body($refused->getMessage())
            ->danger()
            ->send();
    }

    private static function maximumLabel(): string
    {
        return DemoCopyAmount::format(DemoCopyAmount::MAXIMUM_ABSOLUTE_CENTS);
    }

    private static function userId(): ?int
    {
        $id = auth()->id();

        return is_int($id) ? $id : null;
    }
}
