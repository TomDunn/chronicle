<?php

declare(strict_types=1);

use Carbon\Carbon;
use Chronicle\Process\Hangup;
use Chronicle\Process\Models\HistoryEntry;
use Chronicle\Process\Models\ProcessInstance;
use Chronicle\Process\ProcessManager;
use Chronicle\Process\Tests\Fixtures\DummyProcess;
use Chronicle\Process\Tests\Fixtures\ProcessWithTimer;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\freezeTime;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

function manager(): ProcessManager
{
    return app(ProcessManager::class);
}

it('claims the name and key pair', function (): void {
    manager()->run(new DummyProcess('user@example.com'));

    expect(DB::table('process_name_key')->get()->all())->toHaveCount(1);

    $claim = DB::table('process_name_key')->sole();

    expect($claim->name)->toBe('dummy')
        ->and($claim->key)->toBe('user@example.com');
});

it('creates a process instance on the first run', function (): void {
    manager()->run(new DummyProcess('user@example.com'));

    $instance = ProcessInstance::query()->sole();

    expect($instance->name)->toBe('dummy')
        ->and($instance->key)->toBe('user@example.com');
});

it('reuses the existing instance on a second run', function (): void {
    manager()->run(new DummyProcess('user@example.com'));
    manager()->run(new DummyProcess('user@example.com'));

    expect(ProcessInstance::query()->count())->toBe(1)
        ->and(DB::table('process_name_key')->count())->toBe(1);
});

it('creates a separate instance per key', function (): void {
    manager()->run(new DummyProcess('one@example.com'));
    manager()->run(new DummyProcess('two@example.com'));

    expect(ProcessInstance::query()->count())->toBe(2);
});

it('records a history entry for each side effect', function (): void {
    manager()->run(new DummyProcess('user@example.com'));

    $labels = HistoryEntry::query()->orderBy('id')->pluck('label')->all();

    expect($labels)->toBe(['reference', 'charge']);
});

it('attaches history entries to their instance', function (): void {
    manager()->run(new DummyProcess('user@example.com'));

    $instance = ProcessInstance::query()->sole();

    expect(HistoryEntry::query()->where('process_instance_id', $instance->id)->count())
        ->toBe(2);
});

it('stamps each history entry with when it was recorded', function (): void {
    manager()->run(new DummyProcess('user@example.com'));

    expect(HistoryEntry::query()->whereNull('recorded_at')->count())->toBe(0);
});

it('returns the value the process returned', function (): void {
    $result = manager()->run(new DummyProcess('user@example.com'));

    expect($result)->toHaveKeys(['reference', 'charge'])
        ->and($result['charge'])->toStartWith('ch-');
});

it('replays recorded side effects instead of re-running them', function (): void {
    manager()->run(new DummyProcess('user@example.com'));

    $recorded = HistoryEntry::query()->orderBy('id')->pluck('payload', 'label')->all();

    manager()->run(new DummyProcess('user@example.com'));

    expect(HistoryEntry::query()->count())->toBe(2)
        ->and(HistoryEntry::query()->orderBy('id')->pluck('payload', 'label')->all())
        ->toBe($recorded);
});

it('rolls back the instance and history when a side effect throws', function (): void {
    //
})->todo();

it('refuses to run when the process guard rejects it', function (): void {
    //
})->todo();

it('suspends rather than completing when a process awaits a signal', function (): void {
    //
})->todo();

it('suspends rather than completing when a process awaits a timer', function (): void {

    $instant = Carbon::parse('2026-08-24T13:00:00');
    travelTo($instant);

    $instance = new ProcessWithTimer('user@example.com');
    $hangup = manager()->run($instance);

    expect($hangup)->toBeInstanceOf(Hangup::class);
    /** @var Hangup $hangup */
    expect($hangup->waitingUntil)->toEqual(Carbon::parse('2026-08-25T13:00:00'));

    travel(1)->hours();

    $hangup = manager()->run($instance);
    expect($hangup)->toBeInstanceOf(Hangup::class);

    travel(23)->hours();

    $result = manager()->run($instance);
    expect($result)->toBeArray()->toEqual(['email' => 'user@example.com']);

    expect(HistoryEntry::query()->count())->toBe(1)
        ->and(HistoryEntry::query()->orderBy('id')->pluck('label')->all())
        ->toBe(['wait']);
});
