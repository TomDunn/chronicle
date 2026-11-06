<?php

declare(strict_types=1);

use Carbon\Carbon;
use Chronicle\Process\Steps;
use Chronicle\Process\Steps\ActivityStep;
use Chronicle\Process\Steps\AwaitSignalStep;
use Chronicle\Process\Steps\Step;
use Chronicle\Process\Steps\WaitUntilStep;

it('describes an activity without executing its callback', function (): void {
    $calls = 0;
    $callback = function () use (&$calls): array {
        $calls++;

        return ['receipt' => 'receipt-123'];
    };

    $step = Steps::activity('send-request', $callback, description: 'Send the approval request');

    expect($step)->toBeInstanceOf(ActivityStep::class)
        ->toBeInstanceOf(Step::class)
        ->and($step->stepKey)->toBe('send-request')
        ->and($step->description)->toBe('Send the approval request')
        ->and($step->callback)->toBe($callback)
        ->and($calls)->toBe(0);

    expect(($step->callback)())->toBe(['receipt' => 'receipt-123'])
        ->and($calls)->toBe(1);
});

it('captures a mutable deadline without changing it or retaining its mutability', function (): void {
    $deadline = Carbon::parse('2026-11-06 12:30:00.123456', 'America/New_York');
    $expected = DateTimeImmutable::createFromInterface($deadline);

    $step = Steps::waitUntil('reminder-time', $deadline, description: 'Wait until the reminder time');

    expect($step)->toBeInstanceOf(WaitUntilStep::class)
        ->toBeInstanceOf(Step::class)
        ->and($step->stepKey)->toBe('reminder-time')
        ->and($step->description)->toBe('Wait until the reminder time')
        ->and($step->until)->toBeInstanceOf(DateTimeImmutable::class)
        ->toEqual($expected)
        ->and($deadline->format('Y-m-d H:i:s.u e'))->toBe($expected->format('Y-m-d H:i:s.u e'));

    $deadline->addDay();

    expect($step->until)->toEqual($expected);
});

it('accepts an immutable deadline', function (): void {
    $deadline = new DateTimeImmutable('2026-11-06T12:30:00+00:00');

    $step = Steps::waitUntil('reminder-time', $deadline);

    expect($step->until)->toEqual($deadline)
        ->and($step->description)->toBeNull();
});

it('keeps the signal name separate from the step identity and description', function (): void {
    $step = Steps::awaitSignal('approval-decision', 'approved', description: 'Wait for approval');

    expect($step)->toBeInstanceOf(AwaitSignalStep::class)
        ->toBeInstanceOf(Step::class)
        ->and($step->stepKey)->toBe('approval-decision')
        ->and($step->signalName)->toBe('approved')
        ->and($step->description)->toBe('Wait for approval');
});

it('allows activities and signal waits without operator descriptions', function (): void {
    expect(Steps::activity('send-request', fn (): null => null)->description)->toBeNull()
        ->and(Steps::awaitSignal('approval-decision', 'approved')->description)->toBeNull();
});
