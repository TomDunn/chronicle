<?php

declare(strict_types=1);

use Chronicle\Process\Jobs\ExecuteProcess;
use Chronicle\Process\Models\JournalEntry;
use Chronicle\Process\Models\ProcessInstance;
use Chronicle\Process\Scheduler;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

use function Pest\Laravel\travelTo;

beforeEach(function (): void {
    travelTo(new DateTimeImmutable('2026-11-12 12:00:00'));

    Schema::create('scheduler_jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    config()->set('queue.default', 'scheduler');
    config()->set('queue.connections.scheduler', [
        'driver' => 'database',
        'connection' => 'testing',
        'table' => 'scheduler_jobs',
        'queue' => 'default',
        'retry_after' => 90,
        // The scheduler must insert jobs inside its transaction even when
        // the host normally defers queue publication until after commit.
        'after_commit' => true,
    ]);
});

afterEach(function (): void {
    Sleep::fake(false);
});

/** @param array<string, mixed> $attributes */
function schedulerInstance(array $attributes = []): ProcessInstance
{
    return ProcessInstance::query()->create(array_replace([
        'process_type' => 'scheduler-test',
        'active' => true,
        'status' => 'suspended',
        'claim_token' => null,
        'claim_expires_at' => null,
        'suspended_until' => null,
        'suspended_for_signals' => null,
        'document' => ['preserve' => true],
    ], $attributes))->refresh();
}

/** @param array<string, mixed> $attributes */
function schedulerSignal(ProcessInstance $instance, string $name, array $attributes = []): JournalEntry
{
    return JournalEntry::query()->create(array_replace([
        'process_instance_id' => $instance->id,
        'entry_type' => 'signal',
        'step_key' => $name,
        'consumed_by' => null,
    ], $attributes));
}

/** @return list<string> */
function schedulerQueuedInstanceIds(): array
{
    return DB::table('scheduler_jobs')->orderBy('id')->pluck('payload')
        ->map(function (string $payload): string {
            $data = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            expect($data['displayName'])->toBe(ExecuteProcess::class);

            $job = unserialize($data['data']['command']);
            expect($job)->toBeInstanceOf(ExecuteProcess::class);

            return $job->instance->id;
        })->all();
}

it('dispatches the execution job inside the current transaction', function (): void {
    $instance = schedulerInstance();

    DB::beginTransaction();

    try {
        app(Scheduler::class)->dispatchEligibleProcesses();

        expect(schedulerQueuedInstanceIds())->toBe([$instance->id]);
    } finally {
        DB::rollBack();
    }

    expect(schedulerQueuedInstanceIds())->toBe([]);
});

it('runs both dispatch sources and stops at the dispatch threshold without sleeping', function (): void {
    Sleep::fake();
    Sleep::whenFakingSleep(function (): never {
        throw new RuntimeException('The dispatch threshold should already have been reached');
    });
    $eligible = schedulerInstance();
    $expired = schedulerInstance([
        'status' => 'running',
        'claim_token' => Str::uuid()->toString(),
        'claim_expires_at' => now()->subMinute(),
    ]);

    app(Scheduler::class)->run(dispatchBatchSize: 1, runUntilDispatchedAtLeast: 2);

    expect(schedulerQueuedInstanceIds())->toBe([$eligible->id, $expired->id]);
    Sleep::assertNeverSlept();
});

it('backs off while idle and resets the streak after dispatching work', function (): void {
    Sleep::fake();
    $sleeps = 0;
    $ids = [];
    Sleep::whenFakingSleep(function () use (&$sleeps, &$ids): void {
        $sleeps++;
        expect($sleeps)->toBeLessThanOrEqual(5)
            ->and(DB::connection()->transactionLevel())->toBe(0);

        if ($sleeps === 3) {
            $ids[] = schedulerInstance()->id;
        }

        if ($sleeps === 5) {
            $ids[] = schedulerInstance([
                'status' => 'running',
                'claim_token' => Str::uuid()->toString(),
                'claim_expires_at' => now()->subMinute(),
            ])->id;
        }
    });

    app(Scheduler::class)->run(runUntilDispatchedAtLeast: 2);

    expect(schedulerQueuedInstanceIds())->toBe($ids);
    Sleep::assertSequence([
        Sleep::for(1)->seconds(),
        Sleep::for(2)->seconds(),
        Sleep::for(4)->seconds(),
        Sleep::for(1)->seconds(),
        Sleep::for(2)->seconds(),
    ]);
});

it('caps idle backoff at sixty seconds', function (): void {
    Sleep::fake();
    $sleeps = 0;
    Sleep::whenFakingSleep(function () use (&$sleeps): void {
        $sleeps++;
        expect($sleeps)->toBeLessThanOrEqual(8);

        if ($sleeps === 8) {
            schedulerInstance();
        }
    });

    app(Scheduler::class)->run(runUntilDispatchedAtLeast: 1);

    Sleep::assertSequence([
        Sleep::for(1)->seconds(),
        Sleep::for(2)->seconds(),
        Sleep::for(4)->seconds(),
        Sleep::for(8)->seconds(),
        Sleep::for(16)->seconds(),
        Sleep::for(32)->seconds(),
        Sleep::for(60)->seconds(),
        Sleep::for(60)->seconds(),
    ]);
});

it('dispatches a suspended instance without a wait condition', function (): void {
    $instance = schedulerInstance();

    app(Scheduler::class)->dispatchEligibleProcesses();

    expect($instance->fresh()->status)->toBe('pending')
        ->and($instance->fresh()->active)->toBeTrue()
        ->and($instance->fresh()->document)->toBe(['preserve' => true])
        ->and($instance->fresh()->claim_token)->toBeNull()
        ->and(schedulerQueuedInstanceIds())->toBe([$instance->id]);
});

it('dispatches timers only once they are due', function (int $seconds, bool $eligible): void {
    $instance = schedulerInstance(['suspended_until' => now()->addSeconds($seconds)]);

    app(Scheduler::class)->dispatchEligibleProcesses();

    expect($instance->fresh()->status)->toBe($eligible ? 'pending' : 'suspended')
        ->and(schedulerQueuedInstanceIds())->toBe($eligible ? [$instance->id] : []);
})->with([
    'past' => [-1, true],
    'exactly now' => [0, true],
    'future' => [1, false],
]);

it('dispatches a signal wait when any requested signal matches without consuming it', function (): void {
    $instance = schedulerInstance(['suspended_for_signals' => ['approved', 'rejected']]);
    $signal = schedulerSignal($instance, 'rejected');
    schedulerSignal($instance, 'rejected');
    $journal = JournalEntry::query()->orderBy('id')->get()->map->getRawOriginal()->all();

    app(Scheduler::class)->dispatchEligibleProcesses();

    expect($instance->fresh()->status)->toBe('pending')
        ->and(schedulerQueuedInstanceIds())->toBe([$instance->id])
        ->and($signal->fresh()->consumed_by)->toBeNull()
        ->and(JournalEntry::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($journal);
});

it('does not dispatch a signal wait without an unconsumed matching signal for that instance', function (string $scenario): void {
    $instance = schedulerInstance(['suspended_for_signals' => ['approved']]);

    match ($scenario) {
        'missing' => null,
        'wrong name' => schedulerSignal($instance, 'rejected'),
        'other instance' => schedulerSignal(schedulerInstance(), 'approved'),
        'wrong entry type' => schedulerSignal($instance, 'approved', ['entry_type' => 'activity']),
        'consumed' => schedulerSignal($instance, 'approved', [
            'consumed_by' => schedulerSignal($instance, 'wait', ['entry_type' => 'signal_wait'])->id,
        ]),
    };

    app(Scheduler::class)->dispatchEligibleProcesses();

    expect($instance->fresh()->status)->toBe('suspended')
        ->and(schedulerQueuedInstanceIds())->not->toContain($instance->id);
})->with(['missing', 'wrong name', 'other instance', 'wrong entry type', 'consumed']);

it('wakes on either a due timer or a matching signal', function (bool $timerDue): void {
    $instance = schedulerInstance([
        'suspended_until' => $timerDue ? now() : now()->addHour(),
        'suspended_for_signals' => ['approved'],
    ]);

    if (! $timerDue) {
        schedulerSignal($instance, 'approved');
    }

    app(Scheduler::class)->dispatchEligibleProcesses();

    expect(schedulerQueuedInstanceIds())->toBe([$instance->id]);
})->with(['timer' => [true], 'signal' => [false]]);

it('applies active suspended and unclaimed filters to every wake condition', function (array $attributes, string $wake): void {
    $instance = schedulerInstance(array_replace(match ($wake) {
        'immediate' => [],
        'timer' => ['suspended_until' => now()->subSecond()],
        'signal' => ['suspended_for_signals' => ['approved']],
    }, $attributes));

    if ($wake === 'signal') {
        schedulerSignal($instance, 'approved');
    }
    $original = $instance->getRawOriginal();

    app(Scheduler::class)->dispatchEligibleProcesses();

    expect($instance->fresh()->getRawOriginal())->toBe($original)
        ->and(schedulerQueuedInstanceIds())->toBe([]);
})->with([
    'inactive' => [['active' => false]],
    'pending' => [['status' => 'pending']],
    'running' => [['status' => 'running']],
    'failed' => [['status' => 'failed']],
    'completed' => [['status' => 'completed']],
    'claimed' => [['claim_token' => 'existing-claim', 'claim_expires_at' => '2026-11-12 11:00:00']],
])->with(['immediate', 'timer', 'signal']);

it('recovers expired execution claims and clears both claim fields', function (int $seconds, bool $expired): void {
    $instance = schedulerInstance([
        'status' => 'running',
        'claim_token' => Str::uuid()->toString(),
        'claim_expires_at' => now()->addSeconds($seconds),
    ]);
    $original = $instance->getRawOriginal();

    app(Scheduler::class)->sweepExpiredExecutionClaims();

    if ($expired) {
        expect($instance->fresh()->status)->toBe('pending')
            ->and($instance->fresh()->claim_token)->toBeNull()
            ->and($instance->fresh()->claim_expires_at)->toBeNull()
            ->and($instance->fresh()->active)->toBeTrue()
            ->and($instance->fresh()->document)->toBe(['preserve' => true]);
    } else {
        expect($instance->fresh()->getRawOriginal())->toBe($original);
    }

    expect(schedulerQueuedInstanceIds())->toBe($expired ? [$instance->id] : []);
})->with([
    'expired' => [-1, true],
    'expires now' => [0, true],
    'unexpired' => [1, false],
]);

it('does not recover instances without an active running expired claim', function (array $attributes): void {
    $instance = schedulerInstance(array_replace([
        'status' => 'running',
        'claim_token' => Str::uuid()->toString(),
        'claim_expires_at' => now()->subMinute(),
    ], $attributes));
    $original = $instance->getRawOriginal();

    app(Scheduler::class)->sweepExpiredExecutionClaims();

    expect($instance->fresh()->getRawOriginal())->toBe($original)
        ->and(schedulerQueuedInstanceIds())->toBe([]);
})->with([
    'inactive' => [['active' => false]],
    'pending' => [['status' => 'pending']],
    'suspended' => [['status' => 'suspended']],
    'failed' => [['status' => 'failed']],
    'completed' => [['status' => 'completed']],
    'missing token' => [['claim_token' => null]],
    'missing expiry' => [['claim_expires_at' => null]],
]);

// Both entry points promise the same batching and transactional behavior.
dataset('scheduler operations', [
    'eligible processes' => ['dispatchEligibleProcesses', 'suspended'],
    'expired execution claims' => ['sweepExpiredExecutionClaims', 'running'],
]);

it('dispatches at most the requested batch in ascending instance id order', function (string $method, string $status, ?int $batchSize): void {
    $instances = [];
    // Insert out of order so the test distinguishes id order from insertion order.
    foreach ([6, 2, 4, 1, 5, 3] as $number) {
        $instances[] = schedulerInstance([
            'id' => sprintf('01KAAAAAAAAAAAAAAAAAAAAA%02d', $number),
            'status' => $status,
            'claim_token' => $status === 'running' ? Str::uuid()->toString() : null,
            'claim_expires_at' => $status === 'running' ? now()->subMinute() : null,
        ]);
    }
    $ids = array_map(fn (ProcessInstance $instance): string => $instance->id, $instances);
    sort($ids);

    $scheduler = app(Scheduler::class);

    if ($batchSize === null) {
        $scheduler->$method();
    } else {
        $scheduler->$method($batchSize);
    }

    $expected = array_slice($ids, 0, $batchSize ?? 5);
    expect(schedulerQueuedInstanceIds())->toBe($expected)
        ->and(ProcessInstance::query()->where('status', 'pending')->orderBy('id')->pluck('id')->all())->toBe($expected);

    // A subsequent pass must pick up remaining work without dispatching the
    // instances already moved to pending a second time.
    $scheduler->$method();
    expect(schedulerQueuedInstanceIds())->toBe($ids);
})->with('scheduler operations')->with(['default batch' => [null], 'custom batch' => [2]]);

it('does nothing when no instances are eligible', function (string $method, string $status): void {
    app(Scheduler::class)->$method();

    expect(schedulerQueuedInstanceIds())->toBe([]);
})->with('scheduler operations');

it('inserts jobs before commit and rolls them back with the process state', function (string $method, string $status): void {
    $instance = schedulerInstance([
        'status' => $status,
        'claim_token' => $status === 'running' ? Str::uuid()->toString() : null,
        'claim_expires_at' => $status === 'running' ? now()->subMinute() : null,
    ]);
    $original = $instance->getRawOriginal();

    DB::beginTransaction();

    try {
        app(Scheduler::class)->$method();

        expect($instance->fresh()->status)->toBe('pending')
            ->and(schedulerQueuedInstanceIds())->toBe([$instance->id]);
    } finally {
        DB::rollBack();
    }

    expect($instance->fresh()->getRawOriginal())->toBe($original)
        ->and(schedulerQueuedInstanceIds())->toBe([]);
})->with('scheduler operations');

it('rolls back the entire batch when enqueueing a later job fails', function (string $method, string $status): void {
    foreach (range(1, 2) as $number) {
        schedulerInstance([
            'status' => $status,
            'claim_token' => $status === 'running' ? Str::uuid()->toString() : null,
            'claim_expires_at' => $status === 'running' ? now()->subMinute() : null,
        ]);
    }
    $original = ProcessInstance::query()->orderBy('id')->get()->map->getRawOriginal()->all();
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER reject_second_scheduler_job
        BEFORE INSERT ON scheduler_jobs
        WHEN (SELECT COUNT(*) FROM scheduler_jobs) >= 1
        BEGIN
            SELECT RAISE(ABORT, 'Queue unavailable');
        END
        SQL);

    expect(fn () => app(Scheduler::class)->$method())->toThrow(QueryException::class, 'Queue unavailable');

    expect(ProcessInstance::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($original)
        ->and(schedulerQueuedInstanceIds())->toBe([])
        ->and(DB::connection()->transactionLevel())->toBe(0);
})->with('scheduler operations');
