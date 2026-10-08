<?php

use App\Application\DemoCopy\DemoCopyResponseSanitizer;
use App\Models\DemoCopyOperationType;

beforeEach(function () {
    config([
        'etoro.api_key' => 'sanitizer-api-key-sentinel',
        'etoro.user_key' => 'sanitizer-user-key-sentinel',
    ]);
});

it('redacts every configured eToro key from every allow-listed string and the reason', function () {
    $sanitizer = app(DemoCopyResponseSanitizer::class);

    $payload = $sanitizer->payload(DemoCopyOperationType::Poll, [
        'referenceID' => 'sanitizer-api-key-sentinel',
        'parentUsername' => 'x sanitizer-user-key-sentinel y',
        'failReason' => "line\nsanitizer-api-key-sentinel\n",
        'error' => 'sanitizer-user-key-sentinel',
        'errorMessage' => 'prefix sanitizer-api-key-sentinelsuffix',
    ]);

    expect($payload)->toBe([
        'referenceID' => '[redacted]',
        'parentUsername' => 'x [redacted] y',
        'failReason' => 'line [redacted]',
        'error' => '[redacted]',
        'errorMessage' => 'prefix [redacted]suffix',
    ])
        ->and($sanitizer->reason('Not sent: sanitizer-user-key-sentinel'))->toBe('Not sent: [redacted]')
        ->and($sanitizer->errorMessage(['errorMessage' => 'key sanitizer-api-key-sentinel']))->toBe('key [redacted]');
});

it('redacts before truncating, so a key at the cut-off point never leaks partially', function () {
    $reason = app(DemoCopyResponseSanitizer::class)->reason(str_repeat('a', 490).'sanitizer-api-key-sentinel');

    expect($reason)->toBe(str_repeat('a', 490).'[redacted]');
});

it('does not redact with empty, blank or short configured keys', function (mixed $secret) {
    config(['etoro.api_key' => $secret, 'etoro.user_key' => $secret]);

    expect(app(DemoCopyResponseSanitizer::class)->reason('Copy limit reached abc'))->toBe('Copy limit reached abc');
})->with([
    'null' => [null],
    'empty' => [''],
    'blank' => ['          '],
    'short' => ['abc'],
    'padded short' => ['  abc   '],
]);

it('redacts a JSON-escaped key', function (string $leaked) {
    config(['etoro.api_key' => 'abc/def+ghi=é-key']);

    expect(app(DemoCopyResponseSanitizer::class)->reason("Bad key {$leaked} sent"))->toBe('Bad key [redacted] sent');
})->with([
    'escaped slashes and unicode' => ['abc\/def+ghi=\\'.'u00e9-key'],
    'uppercase unicode escape' => ['abc\/def+ghi=\\'.'u00E9-key'],
    'escaped slashes only' => ['abc\/def+ghi=é-key'],
    'escaped unicode only' => ['abc/def+ghi=\\'.'u00e9-key'],
]);

it('redacts a URL-encoded key', function (string $leaked) {
    config(['etoro.user_key' => 'abc/def+ghi= jkl']);

    $payload = app(DemoCopyResponseSanitizer::class)->payload(DemoCopyOperationType::Poll, [
        'failReason' => "GET /x?key={$leaked}&y=1",
    ]);

    expect($payload)->toBe(['failReason' => 'GET /x?key=[redacted]&y=1']);
})->with([
    'urlencode' => ['abc%2Fdef%2Bghi%3D+jkl'],
    'rawurlencode' => ['abc%2Fdef%2Bghi%3D%20jkl'],
    'lowercase hex' => ['abc%2fdef%2bghi%3d%20jkl'],
]);

it('redacts a key without special characters in every form', function () {
    config(['etoro.api_key' => 'PlainKey1234567890']);

    expect(app(DemoCopyResponseSanitizer::class)->reason('a PlainKey1234567890 b plainkey1234567890 c'))
        ->toBe('a [redacted] b [redacted] c');
});

it('never leaves a key prefix at the end of a truncated or upstream-cut string', function (string $value, string $expected) {
    config(['etoro.api_key' => 'abc/def+ghi=sentinel-key']);

    $reason = app(DemoCopyResponseSanitizer::class)->reason($value);

    expect($reason)->toBe($expected)
        ->and(mb_strlen($reason))->toBeLessThanOrEqual(500)
        ->and($reason)->not->toContain('abc/def+')
        ->and($reason)->not->toContain('abc\/def+')
        ->and($reason)->not->toContain('abc%2Fdef');
})->with([
    'literal across the cut' => [str_repeat('a', 480).'abc/def+ghi=sentinel-key-xyz', str_repeat('a', 480).'[redacted]-xyz'],
    'literal prefix cut by the limit' => [str_repeat('a', 485).'abc/def+ghi=sentinel', str_repeat('a', 485).'[redacted]…'],
    'JSON prefix cut by upstream' => ['upstream: abc\/def+gh', 'upstream: [redacted]'],
    'URL-encoded prefix cut by upstream' => ['upstream: abc%2Fdef%2B', 'upstream: [redacted]'],
    'prefix at the truncation point' => [str_repeat('a', 491).'abc/def+ghi='.str_repeat('z', 20), str_repeat('a', 489).'[redacted]…'],
    'short prefix is kept' => ['ends with abc/def', 'ends with abc/def'],
]);
