<?php

declare(strict_types=1);

use Chronicle\Process\Models\HistoryEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('runs against an in-memory sqlite database', function (): void {
    expect(config('database.default'))->toBe('testing')
        ->and(DB::connection()->getDriverName())->toBe('sqlite')
        ->and(DB::connection()->getDatabaseName())->toBe(':memory:');
});

it('runs the package migrations', function (): void {
    expect(Schema::hasTable('process_name_key'))->toBeTrue()
        ->and(Schema::hasTable('process_instance'))->toBeTrue()
        ->and(Schema::hasTable('process_instance_history_entry'))->toBeTrue();
});

it('creates the history entry columns', function (): void {
    expect(Schema::getColumnListing('process_instance_history_entry'))
        ->toContain('id', 'process_instance_id', 'label', 'payload', 'out_of_band', 'recorded_at');
});

it('reads back what it writes', function (): void {
    $instanceId = DB::table('process_instance')->insertGetId([
        'name' => 'onboarding',
        'key' => 'user@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('process_instance_history_entry')->insert([
        'process_instance_id' => $instanceId,
        'label' => 'reference',
        'payload' => json_encode(['value' => 'ref-1234']),
        'recorded_at' => now(),
    ]);

    $entry = DB::table('process_instance_history_entry')->sole();

    expect($entry->label)->toBe('reference')
        ->and(json_decode($entry->payload, true))->toBe(['value' => 'ref-1234'])
        ->and($entry->out_of_band)->toBe(0);
});

it('starts each test from an empty schema', function (): void {
    expect(DB::table('process_instance')->count())->toBe(0);
});

it('persists a history entry through the model', function (): void {
    $instanceId = DB::table('process_instance')->insertGetId([
        'name' => 'onboarding',
        'key' => 'user@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $entry = HistoryEntry::factory()->create([
        'process_instance_id' => $instanceId,
        'label' => 'reference',
        'payload' => ['value' => 'ref-1234'],
    ]);

    expect($entry->exists)->toBeTrue()
        ->and(HistoryEntry::query()->count())->toBe(1);

    $fresh = $entry->fresh();

    expect($fresh->payload)->toBe(['value' => 'ref-1234'])
        ->and($fresh->out_of_band)->toBeFalse()
        ->and($fresh->recorded_at)->toBeInstanceOf(Carbon::class);
});
