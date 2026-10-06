<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * A FakeJob carrying a payload UUID, like a real queued job — the plain
 * FakeJob from withFakeQueueInteractions() has none.
 */
function fakeQueueJobWithUuid(string $uuid = 'queue-job-uuid-1'): FakeJob
{
    return new class($uuid) extends FakeJob
    {
        public function __construct(private readonly string $payloadUuid) {}

        public function uuid(): string
        {
            return $this->payloadUuid;
        }
    };
}
