<?php

declare(strict_types=1);

use Chronicle\Process\Chronicle as ChronicleService;
use Chronicle\Process\Data\ProcessHandle;
use Chronicle\Process\Facades\Chronicle;
use Chronicle\Process\Models\ProcessInstance;
use Chronicle\Process\Tests\Fixtures\LaunchableProcess;

it('resolves the same Chronicle singleton through the facade and container', function (): void {
    $service = app(ChronicleService::class);

    expect($service)->toBeInstanceOf(ChronicleService::class)
        ->and(app(ChronicleService::class))->toBe($service)
        ->and(Chronicle::getFacadeRoot())->toBe($service);
});

it('shares process registrations between the Chronicle facade and resolved service', function (): void {
    $service = Chronicle::registerProcess(LaunchableProcess::class);

    expect($service)->toBe(app(ChronicleService::class));

    $handle = app(ChronicleService::class)->launch(LaunchableProcess::class, ['message' => 'hello'], 'facade-key');

    expect($handle)->toBeInstanceOf(ProcessHandle::class)
        ->and($handle->id())->toBe(ProcessInstance::query()->sole()->id)
        ->and($handle->key())->toBe('facade-key');
});
