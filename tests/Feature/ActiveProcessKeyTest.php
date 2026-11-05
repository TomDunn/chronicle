<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('allows inactive rows to share a key with an active row', function (): void {
    $instance = [
        'process_type' => 'example',
        'key' => 'shared-key',
        'active' => false,
        'status' => 'completed',
        'document' => '{}',
    ];

    DB::table('process_instance')->insert([$instance, $instance]);
    DB::table('process_instance')->insert(array_replace($instance, ['active' => true, 'status' => 'pending']));

    expect(DB::table('process_instance')->count())->toBe(3);
});

it('rejects a second active row with the same key', function (): void {
    $instance = [
        'process_type' => 'example',
        'key' => 'shared-key',
        'active' => true,
        'status' => 'pending',
        'document' => '{}',
    ];

    DB::table('process_instance')->insert($instance);

    expect(fn () => DB::table('process_instance')->insert($instance))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows a key to be reused after its active row becomes inactive', function (): void {
    $instance = [
        'process_type' => 'example',
        'key' => 'shared-key',
        'active' => true,
        'status' => 'pending',
        'document' => '{}',
    ];

    DB::table('process_instance')->insert($instance);
    DB::table('process_instance')->update(['active' => false, 'status' => 'completed']);
    DB::table('process_instance')->insert($instance);

    expect(DB::table('process_instance')->where('active', true)->count())->toBe(1)
        ->and(DB::table('process_instance')->count())->toBe(2);
});
