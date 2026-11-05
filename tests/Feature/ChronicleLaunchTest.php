<?php

declare(strict_types=1);

use Chronicle\Process\Chronicle;
use Chronicle\Process\Data\ProcessHandle;
use Chronicle\Process\Models\ProcessInstance;
use Chronicle\Process\Tests\Fixtures\LaunchableProcess;
use Illuminate\Support\Str;

it('rejects launching an unregistered process', function (): void {
    $chronicle = new Chronicle;

    expect(fn () => $chronicle->launch(LaunchableProcess::class, [], 'shared-key'))
        ->toThrow(InvalidArgumentException::class);

    expect(ProcessInstance::query()->count())->toBe(0);
});

it('launches a registered process as an active pending instance', function (): void {
    $chronicle = (new Chronicle)->registerProcess(new LaunchableProcess);

    $handle = $chronicle->launch(LaunchableProcess::class, [], 'shared-key');

    expect($handle)->toBeInstanceOf(ProcessHandle::class);

    $instance = ProcessInstance::query()->sole();

    expect(Str::isUlid($instance->id))->toBeTrue()
        ->and($instance->process_type)->toBe(LaunchableProcess::getProcessType())
        ->and($instance->key)->toBe('shared-key')
        ->and($instance->active)->toBeTrue()
        ->and($instance->status)->toBe('pending')
        ->and($instance->claim_token)->toBeNull()
        ->and($instance->claim_expires_at)->toBeNull()
        ->and($handle->id())->toBe($instance->id)
        ->and($handle->type())->toBe($instance->process_type)
        ->and($handle->key())->toBe($instance->key)
        ->and($handle->status())->toBe('pending');
});

it('returns the existing active instance without resetting it', function (string $status): void {
    $chronicle = (new Chronicle)->registerProcess(new LaunchableProcess);
    $instance = ProcessInstance::query()->create([
        'process_type' => LaunchableProcess::getProcessType(),
        'key' => 'shared-key',
        'active' => true,
        'status' => $status,
        'claim_token' => (string) Str::uuid(),
        'claim_expires_at' => now()->addMinute(),
        'document' => ['existing' => true],
    ]);
    $original = $instance->getRawOriginal();

    $handle = $chronicle->launch(LaunchableProcess::class, [], 'shared-key');

    expect($handle)->toBeInstanceOf(ProcessHandle::class);

    expect($handle->id())->toBe($instance->id)
        ->and($handle->type())->toBe($instance->process_type)
        ->and($handle->key())->toBe($instance->key)
        ->and($handle->status())->toBe($status)
        ->and(ProcessInstance::query()->count())->toBe(1)
        ->and($instance->fresh()->getRawOriginal())->toBe($original);
})->with(['pending', 'running', 'suspended']);

it('creates a new instance when the same key only has an inactive instance', function (string $status): void {
    $chronicle = (new Chronicle)->registerProcess(new LaunchableProcess);
    $previous = ProcessInstance::query()->create([
        'process_type' => LaunchableProcess::getProcessType(),
        'key' => 'shared-key',
        'active' => false,
        'status' => $status,
        'document' => ['previous' => true],
    ]);
    $original = $previous->getRawOriginal();

    $handle = $chronicle->launch(LaunchableProcess::class, [], 'shared-key');

    expect($handle)->toBeInstanceOf(ProcessHandle::class);

    $instance = ProcessInstance::query()->where('active', true)->sole();

    expect($instance->id)->not->toBe($previous->id)
        ->and($instance->status)->toBe('pending')
        ->and($instance->key)->toBe('shared-key')
        ->and($handle->id())->toBe($instance->id)
        ->and(ProcessInstance::query()->count())->toBe(2)
        ->and($previous->fresh()->getRawOriginal())->toBe($original);
})->with(['completed', 'failed']);

it('creates separate active instances for different keys', function (): void {
    $chronicle = (new Chronicle)->registerProcess(new LaunchableProcess);

    $first = $chronicle->launch(LaunchableProcess::class, [], 'first-key');
    $second = $chronicle->launch(LaunchableProcess::class, [], 'second-key');

    expect($first)->toBeInstanceOf(ProcessHandle::class)
        ->and($second)->toBeInstanceOf(ProcessHandle::class);

    expect($first->id())->not->toBe($second->id())
        ->and($first->key())->toBe('first-key')
        ->and($second->key())->toBe('second-key')
        ->and(ProcessInstance::query()->where('active', true)->count())->toBe(2);
});

it('creates a new instance for each launch without a key', function (): void {
    $chronicle = (new Chronicle)->registerProcess(new LaunchableProcess);

    $first = $chronicle->launch(LaunchableProcess::class, []);
    $second = $chronicle->launch(LaunchableProcess::class, []);

    expect($first)->toBeInstanceOf(ProcessHandle::class)
        ->and($second)->toBeInstanceOf(ProcessHandle::class);

    expect($first->id())->not->toBe($second->id())
        ->and($first->key())->toBeNull()
        ->and($second->key())->toBeNull()
        ->and(ProcessInstance::query()->whereNull('key')->count())->toBe(2);
});
