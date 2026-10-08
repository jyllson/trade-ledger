<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\DemoCopy\DemoCopyRefused;
use App\Application\DemoCopy\PollDemoCopyOutcome;
use App\Models\DemoCopyOperation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Bounded outcome polling of one demo copy start/adjust (D-050): one GET
 * per attempt, at most MAX_POLLS attempts over roughly ten minutes, then
 * the registration is recorded as `unknown` — never as succeeded.
 *
 * Queued instead of polling inside the web request: the register call is
 * asynchronous on eToro's side with no documented completion time, and a
 * blocking loop would hold the Livewire request (and the shared request
 * budget) for an unbounded wait. Each attempt is one permit of the shared
 * `etoro-api` budget (D-039); a locally refused poll is just another
 * attempt. The job never re-sends the registration itself.
 */
final class PollDemoCopyOutcomeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int FIRST_POLL_DELAY_SECONDS = 5;

    public const int MAX_POLLS = 10;

    /** Delay before poll n+1, in seconds (sum ≈ 9.5 minutes). */
    private const array BACKOFF_SECONDS = [5, 10, 20, 30, 60, 60, 90, 120, 180];

    public int $tries = self::MAX_POLLS;

    /** One HTTP request of at most `etoro.timeout_seconds` (20 s). */
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    public int $uniqueFor = 900;

    public function __construct(public DemoCopyOperation $registration) {}

    public function uniqueId(): string
    {
        return (string) $this->registration->id;
    }

    public function handle(PollDemoCopyOutcome $pollDemoCopyOutcome): void
    {
        try {
            $pollDemoCopyOutcome->handle($this->registration);
        } catch (DemoCopyRefused $refused) {
            $pollDemoCopyOutcome->giveUp($this->registration, 'Polling stopped: '.$refused->getMessage());

            return;
        }

        $this->registration->refresh();

        if (! $this->registration->status->awaitsOutcome()) {
            return;
        }

        if ($this->attempts() >= self::MAX_POLLS) {
            $pollDemoCopyOutcome->giveUp($this->registration, 'No terminal outcome after '.self::MAX_POLLS.' status polls.');

            return;
        }

        $this->release(self::BACKOFF_SECONDS[min($this->attempts() - 1, count(self::BACKOFF_SECONDS) - 1)]);
    }

    public function failed(?Throwable $exception): void
    {
        app(PollDemoCopyOutcome::class)->giveUp($this->registration, 'Status polling job failed.');
    }
}
