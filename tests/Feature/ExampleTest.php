<?php

declare(strict_types=1);

use Chronicle\Process\Process;

it('resolves the singleton', function () {
    expect(app(Process::class))->toBeInstanceOf(Process::class);
});

it('returns the same instance from the container', function () {
    expect(app(Process::class))->toBe(app(Process::class));
});

it('merges the package config', function () {
    expect(config('chronicle.placeholder'))->toBe('default');
});

it('loads the package views', function () {
    expect(view()->exists('chronicle::placeholder'))->toBeTrue();
});

it('registers the artisan command', function () {
    $this->artisan('chronicle:placeholder')
        ->expectsOutputToContain('Process placeholder command executed.')
        ->assertSuccessful();
});
