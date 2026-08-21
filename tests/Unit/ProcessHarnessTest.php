<?php

declare(strict_types=1);

use Chronicle\Process\Tests\Fixtures\DummyProcess;
use Chronicle\Process\Tests\Support\ProcessHarness;

it('runs a process to completion and returns its result', function (): void {
    $result = ProcessHarness::for(new DummyProcess)->run();

    expect($result->value)->toHaveKeys(['reference', 'charge'])
        ->and($result->value['charge'])->toStartWith('ch-');
});

it('yields one step per side effect, in order', function (): void {
    $result = ProcessHarness::for(new DummyProcess)->run();

    expect($result->steps)->toHaveCount(2)
        ->and($result->executed)->toBe(['reference', 'charge']);
});

it('feeds the result of one side effect into the next', function (): void {
    $result = ProcessHarness::for(new DummyProcess)->run();

    expect($result->value['charge'])
        ->toBe('ch-'.strtoupper($result->value['reference']));
});

it('records the result of every side effect it executes', function (): void {
    $result = ProcessHarness::for(new DummyProcess)->run();

    expect($result->history)->toBe([
        'reference' => $result->value['reference'],
        'charge' => $result->value['charge'],
    ]);
});

it('replays recorded results without re-running the side effects', function (): void {
    $first = ProcessHarness::for(new DummyProcess)->run();

    $replay = ProcessHarness::for(new DummyProcess)
        ->withHistory($first->history)
        ->run();

    expect($replay->executed)->toBe([])
        ->and($replay->value)->toBe($first->value);
});

it('re-runs only the side effects missing from history', function (): void {
    $first = ProcessHarness::for(new DummyProcess)->run();

    $replay = ProcessHarness::for(new DummyProcess)
        ->withHistory(['reference' => $first->history['reference']])
        ->run();

    expect($replay->executed)->toBe(['charge'])
        ->and($replay->value['reference'])->toBe($first->value['reference']);
});
