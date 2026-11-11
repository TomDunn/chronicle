<?php

declare(strict_types=1);

use Carbon\Carbon;
use Chronicle\Process\Chronicle;
use Chronicle\Process\Contracts\Payload;
use Chronicle\Process\Data\ArrayPayload;
use Chronicle\Process\Data\CommonScalarPayload;
use Chronicle\Process\Data\ExceptionPayload;
use Chronicle\Process\Data\ProcessHandle;
use Chronicle\Process\Enums\EntryType;
use Chronicle\Process\Executor;
use Chronicle\Process\Models\JournalEntry;
use Chronicle\Process\Models\ProcessInstance;
use Chronicle\Process\Steps;
use Chronicle\Process\Steps\Step;
use Chronicle\Process\Tests\Fixtures\ExecutorProcess;
use Chronicle\Process\Tests\Fixtures\LaunchPayload;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ramsey\Uuid\UuidInterface;

use function Pest\Laravel\travelTo;

beforeEach(function (): void {
    travelTo(Carbon::parse('2026-11-06 12:00:00'));
});

/**
 * @param  Closure(Payload): mixed  $handler
 * @param  array<string, mixed>|Payload  $payload
 */
function launchExecutorProcess(Closure $handler, Payload|array $payload = []): ProcessHandle
{
    $chronicle = (new Chronicle())
        ->registerProcess(ExecutorProcess::class)
        ->registerPayload(LaunchPayload::class)
        ->registerPayload(CommonScalarPayload::class)
        ->registerPayload(ExceptionPayload::class)
        ->registerPayload(ArrayPayload::class);

    app()->instance(Chronicle::class, $chronicle);
    app()->bind(ExecutorProcess::class, fn(): ExecutorProcess => new ExecutorProcess($handler));

    return $chronicle->launch(ExecutorProcess::class, $payload, 'executor-key');
}

/**
 * Incoming signals identify their name through step_key.
 *
 * @param  array<string, mixed>  $payload
 */
function recordExecutorSignal(ProcessHandle $handle, string $name, array $payload = []): JournalEntry
{
    return JournalEntry::query()->create([
        'process_instance_id' => $handle->id(),
        'entry_type' => 'signal',
        'step_key' => $name,
        'sequence' => null,
        'payload_type' => ArrayPayload::make($payload)->type(),
        'payload' => $payload,
    ]);
}

function createExecutorEffectsTable(): void
{
    Schema::create('executor_effects', function (Blueprint $table): void {
        $table->string('name')->primary();
    });
}

it('claims a pending instance and records an activity using the deserialized launch payload', function (Payload|array $payload, string $payloadClass, array $expected): void {
    $observed = null;
    $handle = launchExecutorProcess(function (Payload $input) use (&$observed): Generator {
        $result = yield Steps::activity('read-input', function () use ($input, &$observed): array {
            $observed = ProcessInstance::query()->sole();

            return ['class' => $input::class, 'input' => $input->jsonSerialize()];
        }, description: 'Read the launch input');

        return $result;
    }, $payload);

    app(Executor::class)->execute($handle);

    $instance = ProcessInstance::query()->sole();

    expect($instance->status)->toBe('completed')
        ->and($instance->active)->toBeFalse()
        ->and($instance->result)->toBe(['class' => $payloadClass, 'input' => $expected])
        ->and($instance->claim_token)->toBeNull()
        ->and($instance->claim_expires_at)->toBeNull()
        ->and($observed->status)->toBe('running')
        ->and(Str::isUuid($observed->claim_token))->toBeTrue()
        ->and($observed->claim_expires_at->isAfter(now()))->toBeTrue();

    $entries = JournalEntry::query()->orderBy('sequence')->get();

    expect($entries->pluck('entry_type')->all())->toBe([EntryType::Launch, EntryType::Activity])
        ->and($entries->pluck('sequence')->all())->toBe([0, 1])
        ->and($entries[1]->process_instance_id)->toBe($handle->id())
        ->and($entries[1]->step_key)->toBe('read-input')
        ->and($entries[1]->description)->toBe('Read the launch input')
        ->and($entries[1]->recorded_at)->toEqual(now());
})->with([
    'array input' => [['message' => 'hello'], ArrayPayload::class, ['message' => 'hello']],
    'custom input' => [new LaunchPayload(['message' => 'hello']), LaunchPayload::class, ['message' => 'hello']],
]);

it('completes a process that returns without yielding', function (): void {
    $handle = launchExecutorProcess(fn(Payload $input): array => ['input' => $input->jsonSerialize()], ['value' => 42]);

    app(Executor::class)->execute($handle);

    $instance = ProcessInstance::query()->sole();

    expect($instance->status)->toBe('completed')
        ->and($instance->active)->toBeFalse()
        ->and($instance->result)->toBe(['input' => ['value' => 42]])
        ->and($instance->claim_token)->toBeNull()
        ->and($instance->claim_expires_at)->toBeNull()
        ->and(JournalEntry::query()->count())->toBe(1);
});

it('leaves an instance unchanged when it is not eligible to execute', function (string $status, bool $active): void {
    $calls = 0;
    $handle = launchExecutorProcess(function (Payload $input) use (&$calls): null {
        $calls++;

        return null;
    });
    $instance = ProcessInstance::query()->sole();
    $instance->update(['status' => $status, 'active' => $active]);
    $original = $instance->refresh()->getRawOriginal();

    app(Executor::class)->execute($handle);

    expect($calls)->toBe(0)
        ->and($instance->fresh()->getRawOriginal())->toBe($original)
        ->and(JournalEntry::query()->count())->toBe(1);
})->with([
    'inactive pending' => ['pending', false],
    'suspended' => ['suspended', true],
    'failed' => ['failed', true],
    'completed' => ['completed', false],
]);

it('fails without invoking the process when its launch entry is missing', function (): void {
    $calls = 0;
    $handle = launchExecutorProcess(function (Payload $input) use (&$calls): null {
        $calls++;

        return null;
    });
    JournalEntry::query()->delete();

    app(Executor::class)->execute($handle);

    $instance = ProcessInstance::query()->sole();

    expect($instance->status)->toBe('failed')
        ->and($instance->active)->toBeTrue()
        ->and($instance->claim_token)->toBeNull()
        ->and($instance->claim_expires_at)->toBeNull()
        ->and($calls)->toBe(0)
        ->and(JournalEntry::query()->sole()->entry_type)->toBe(EntryType::ProcessError);
});

it('replays activity results with their original value and type', function (mixed $value): void {
    $calls = 0;
    $deadline = now()->addHour()->toImmutable();
    $handle = launchExecutorProcess(function (Payload $input) use (&$calls, $value, $deadline): Generator {
        $recorded = yield Steps::activity('value', function () use (&$calls, $value): mixed {
            $calls++;

            return $value;
        });
        yield Steps::waitUntil('pause', $deadline);

        return ['value' => $recorded];
    });

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->sole()->status)->toBe('suspended')
        ->and($calls)->toBe(1);

    $activity = JournalEntry::query()->where('entry_type', 'activity')->sole();
    $original = $activity->getRawOriginal();
    travelTo(Carbon::instance($deadline));
    ProcessInstance::query()->whereKey($handle->id())->update(['status' => 'pending']);

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->sole()->status)->toBe('completed')
        ->and(ProcessInstance::query()->sole()->result)->toBe(['value' => $value])
        ->and($calls)->toBe(1)
        ->and(JournalEntry::query()->where('entry_type', 'activity')->count())->toBe(1)
        ->and($activity->fresh()->getRawOriginal())->toBe($original);
})->with([
    'string' => ['recorded'],
    'integer' => [42],
    'zero' => [0],
    'float' => [1.25],
    'false' => [false],
    'null' => [null],
    'array' => [['nested' => ['enabled' => true], 'missing' => null]],
]);

it('reconstructs supported object activity results during replay', function (object $value, string $expectedType, Closure $normalize): void {
    $calls = 0;
    $deadline = now()->addHour()->toImmutable();
    $handle = launchExecutorProcess(function (Payload $input) use (&$calls, $value, $expectedType, $normalize, $deadline): Generator {
        $recorded = yield Steps::activity('value', function () use (&$calls, $value): object {
            $calls++;

            return $value;
        });
        yield Steps::waitUntil('pause', $deadline);

        return ['correct_type' => $recorded instanceof $expectedType, 'value' => $normalize($recorded)];
    });

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->sole()->status)->toBe('suspended');

    travelTo(Carbon::instance($deadline));
    ProcessInstance::query()->whereKey($handle->id())->update(['status' => 'pending']);

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->sole()->status)->toBe('completed')
        ->and(ProcessInstance::query()->sole()->result)->toBe(['correct_type' => true, 'value' => $normalize($value)])
        ->and($calls)->toBe(1);
})->with([
    'Carbon' => [
        Carbon::parse('2026-11-06 12:00:00.123456', 'America/New_York'),
        Carbon::class,
        fn(Carbon $value): string => $value->format('Y-m-d\TH:i:s.uP'),
    ],
    'immutable date' => [
        new DateTimeImmutable('2026-11-06T12:00:00.123456+00:00'),
        DateTimeImmutable::class,
        fn(DateTimeImmutable $value): string => $value->format('Y-m-d\TH:i:s.uP'),
    ],
    'UUID' => [Str::uuid(), UuidInterface::class, fn(UuidInterface $value): string => $value->toString()],
    'custom payload' => [
        new LaunchPayload(['reference' => 'ref-123']),
        LaunchPayload::class,
        fn(LaunchPayload $value): array => $value->jsonSerialize(),
    ],
]);

it('replays in journal sequence order and executes only the remaining activities', function (): void {
    $firstCalls = 0;
    $lastCalls = 0;
    $deadline = now()->addHour()->toImmutable();
    $handle = launchExecutorProcess(function (Payload $input) use (&$firstCalls, &$lastCalls, $deadline): Generator {
        $first = yield Steps::activity('first', function () use (&$firstCalls): array {
            $firstCalls++;

            return ['reference' => 'ref-123'];
        });
        yield Steps::waitUntil('pause', $deadline);
        $last = yield Steps::activity('last', function () use (&$lastCalls, $first): array {
            $lastCalls++;

            return ['reference' => $first['reference'], 'finished' => true];
        });

        return $last;
    });

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->sole()->status)->toBe('suspended')
        ->and($firstCalls)->toBe(1)
        ->and($lastCalls)->toBe(0);

    // A later-sorting ULID must not change this activity's replay position.
    JournalEntry::query()->where('entry_type', 'activity')->update(['id' => '7zzzzzzzzzzzzzzzzzzzzzzzzz']);
    travelTo(Carbon::instance($deadline));
    ProcessInstance::query()->whereKey($handle->id())->update(['status' => 'pending']);

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->sole()->status)->toBe('completed')
        ->and(ProcessInstance::query()->sole()->result)->toBe(['reference' => 'ref-123', 'finished' => true])
        ->and($firstCalls)->toBe(1)
        ->and($lastCalls)->toBe(1)
        ->and(JournalEntry::query()->orderBy('sequence')->pluck('step_key')->all())
        ->toBe(['initial_launch', 'first', 'pause', 'last'])
        ->and(JournalEntry::query()->orderBy('sequence')->pluck('sequence')->all())->toBe([0, 1, 2, 3]);
});

it('distinguishes repeated step keys by their journal positions', function (): void {
    $calls = 0;
    $deadline = now()->addHour()->toImmutable();
    $handle = launchExecutorProcess(function (Payload $input) use (&$calls, $deadline): Generator {
        $values = [];

        foreach ([1, 2] as $item) {
            $values[] = yield Steps::activity('item', function () use (&$calls, $item): int {
                $calls++;

                return $item;
            });
        }

        yield Steps::waitUntil('pause', $deadline);

        return ['items' => $values];
    });

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->sole()->status)->toBe('suspended')
        ->and($calls)->toBe(2);

    travelTo(Carbon::instance($deadline));
    ProcessInstance::query()->whereKey($handle->id())->update(['status' => 'pending']);

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->sole()->status)->toBe('completed')
        ->and(ProcessInstance::query()->sole()->result)->toBe(['items' => [1, 2]])
        ->and($calls)->toBe(2)
        ->and(JournalEntry::query()->where('entry_type', 'activity')->orderBy('sequence')->pluck('step_key')->all())
        ->toBe(['item', 'item']);
});

it('suspends until the recorded deadline and resumes at that deadline', function (): void {
    $deadline = now()->addHour()->toImmutable();
    $originalDeadline = $deadline;
    $continued = 0;
    $handle = launchExecutorProcess(function (Payload $input) use (&$deadline, &$continued): Generator {
        yield Steps::waitUntil('deadline', $deadline);
        $continued++;

        return ['finished' => true];
    });

    app(Executor::class)->execute($handle);

    $instance = ProcessInstance::query()->sole();

    expect($instance->status)->toBe('suspended')
        ->and($instance->active)->toBeTrue()
        ->and($instance->claim_token)->toBeNull()
        ->and($instance->claim_expires_at)->toBeNull()
        ->and(Carbon::parse($instance->getRawOriginal('suspended_until'))->equalTo($originalDeadline))->toBeTrue()
        ->and($continued)->toBe(0);

    $wait = JournalEntry::query()->where('step_key', 'deadline')->sole();
    $original = $wait->getRawOriginal();
    $deadline = $deadline->modify('+1 day');
    travelTo(Carbon::instance($originalDeadline)->subSecond());
    $instance->update(['status' => 'pending']);

    app(Executor::class)->execute($handle);

    expect($instance->fresh()->status)->toBe('suspended')
        ->and($continued)->toBe(0)
        ->and($wait->fresh()->getRawOriginal())->toBe($original);

    travelTo(Carbon::instance($originalDeadline));
    $instance->refresh()->update(['status' => 'pending']);

    app(Executor::class)->execute($handle);

    expect($instance->fresh()->status)->toBe('completed')
        ->and($instance->fresh()->result)->toBe(['finished' => true])
        ->and($continued)->toBe(1)
        ->and($wait->fresh()->getRawOriginal())->toBe($original)
        ->and(JournalEntry::query()->count())->toBe(2);
});

it('records a new wait without suspending when its deadline is already reached', function (int $seconds): void {
    $deadline = now()->addSeconds($seconds)->toImmutable();
    $handle = launchExecutorProcess(function (Payload $input) use ($deadline): Generator {
        yield Steps::waitUntil('deadline', $deadline);

        return ['finished' => true];
    });

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->sole()->status)->toBe('completed')
        ->and(ProcessInstance::query()->sole()->result)->toBe(['finished' => true])
        ->and(JournalEntry::query()->where('step_key', 'deadline')->sole()->sequence)->toBe(1);
})->with(['past' => [-1], 'now' => [0]]);

it('suspends for a signal and resumes only when a matching signal is available', function (): void {
    $continued = 0;
    $handle = launchExecutorProcess(function (Payload $input) use (&$continued): Generator {
        yield Steps::awaitSignal('approval-wait', 'approved');
        $continued++;

        return ['approved' => true];
    });

    app(Executor::class)->execute($handle);

    $instance = ProcessInstance::query()->sole();

    expect($instance->status)->toBe('suspended')
        ->and($instance->active)->toBeTrue()
        ->and($instance->claim_token)->toBeNull()
        ->and($instance->claim_expires_at)->toBeNull()
        ->and(json_decode($instance->getRawOriginal('suspended_for_signals'), true))->toBe(['approved'])
        ->and($continued)->toBe(0);

    $wait = JournalEntry::query()->where('step_key', 'approval-wait')->sole();
    $original = $wait->getRawOriginal();
    $unrelated = recordExecutorSignal($handle, 'unrelated');
    $instance->update(['status' => 'pending']);

    app(Executor::class)->execute($handle);

    expect($instance->fresh()->status)->toBe('suspended')
        ->and($continued)->toBe(0)
        ->and($wait->fresh()->getRawOriginal())->toBe($original)
        ->and($unrelated->fresh()->consumed_by)->toBeNull();

    $approved = recordExecutorSignal($handle, 'approved', ['decision' => 'yes']);
    $instance->refresh()->update(['status' => 'pending']);

    app(Executor::class)->execute($handle);

    expect($instance->fresh()->status)->toBe('completed')
        ->and($instance->fresh()->result)->toBe(['approved' => true])
        ->and($continued)->toBe(1)
        ->and($approved->fresh()->consumed_by)->toBe($wait->id)
        ->and($unrelated->fresh()->consumed_by)->toBeNull()
        ->and(JournalEntry::query()->where('step_key', 'approval-wait')->count())->toBe(1);
});

it('consumes an already received signal without suspending or treating it as a yielded step', function (): void {
    $handle = launchExecutorProcess(function (Payload $input): Generator {
        $first = yield Steps::activity('before-signal', fn(): array => ['prepared' => true]);
        yield Steps::awaitSignal('approval-wait', 'approved');

        return $first;
    });
    $signal = recordExecutorSignal($handle, 'approved');

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->sole()->status)->toBe('completed');

    $wait = JournalEntry::query()->where('step_key', 'approval-wait')->sole();

    expect(ProcessInstance::query()->sole()->result)->toBe(['prepared' => true])
        ->and($signal->fresh()->consumed_by)->toBe($wait->id)
        ->and(JournalEntry::query()->whereNotNull('sequence')->orderBy('sequence')->pluck('step_key')->all())
        ->toBe(['initial_launch', 'before-signal', 'approval-wait']);
});

it('does not consume a matching signal belonging to another process instance', function (): void {
    $handle = launchExecutorProcess(function (Payload $input): Generator {
        yield Steps::awaitSignal('approval-wait', 'approved');

        return ['approved' => true];
    });
    $other = app(Chronicle::class)->launch(ExecutorProcess::class, [], 'other-key');
    $signal = recordExecutorSignal($other, 'approved');

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->findOrFail($handle->id())->status)->toBe('suspended')
        ->and(ProcessInstance::query()->findOrFail($other->id())->status)->toBe('pending')
        ->and($signal->fresh()->consumed_by)->toBeNull();
});

it('consumes each signal once and reuses its original wait association during replay', function (): void {
    $handle = launchExecutorProcess(function (Payload $input): Generator {
        yield Steps::awaitSignal('first-approval', 'approved');
        yield Steps::awaitSignal('second-approval', 'approved');

        return ['approved' => true];
    });
    $first = recordExecutorSignal($handle, 'approved', ['number' => 1]);

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->sole()->status)->toBe('suspended');

    $firstWait = JournalEntry::query()->where('step_key', 'first-approval')->sole();
    $secondWait = JournalEntry::query()->where('step_key', 'second-approval')->sole();

    expect($first->fresh()->consumed_by)->toBe($firstWait->id)
        ->and(JournalEntry::query()->where('consumed_by', $secondWait->id)->count())->toBe(0);

    $second = recordExecutorSignal($handle, 'approved', ['number' => 2]);
    ProcessInstance::query()->whereKey($handle->id())->update(['status' => 'pending']);

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->sole()->status)->toBe('completed')
        ->and($first->fresh()->consumed_by)->toBe($firstWait->id)
        ->and($second->fresh()->consumed_by)->toBe($secondWait->id)
        ->and(JournalEntry::query()->where('consumed_by', $firstWait->id)->count())->toBe(1)
        ->and(JournalEntry::query()->where('consumed_by', $secondWait->id)->count())->toBe(1);
});

it('fails replay when the next step key or type changes', function (Step $replacement): void {
    $deadline = now()->addHour()->toImmutable();
    $handle = launchExecutorProcess(function (Payload $input) use ($deadline): Generator {
        yield Steps::activity('original', fn(): array => ['recorded' => true]);
        yield Steps::waitUntil('pause', $deadline);

        return ['finished' => true];
    });

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->sole()->status)->toBe('suspended');

    $original = JournalEntry::query()->orderBy('sequence')->get()->map->getRawOriginal()->all();
    app()->bind(ExecutorProcess::class, fn(): ExecutorProcess => new ExecutorProcess(
        function (Payload $input) use ($replacement, $deadline): Generator {
            yield $replacement;
            yield Steps::waitUntil('pause', $deadline);

            return ['finished' => true];
        },
    ));
    ProcessInstance::query()->whereKey($handle->id())->update(['status' => 'pending']);

    app(Executor::class)->execute($handle);

    $instance = ProcessInstance::query()->sole();

    expect($instance->status)->toBe('failed')
        ->and($instance->active)->toBeTrue()
        ->and($instance->claim_token)->toBeNull()
        ->and($instance->claim_expires_at)->toBeNull()
        ->and(JournalEntry::query()->where('entry_type', 'process_error')->count())->toBe(1)
        ->and(JournalEntry::query()->where('entry_type', '!=', 'process_error')->orderBy('sequence')->get()->map->getRawOriginal()->all())
        ->toBe($original);
})->with([
    'step key' => fn(): Step => Steps::activity('renamed', fn(): never => throw new LogicException('Must not execute')),
    'step type' => fn(): Step => Steps::waitUntil('original', new DateTimeImmutable('2026-11-06 13:00:00')),
]);

it('fails replay when a process returns before consuming its recorded steps', function (): void {
    $deadline = now()->addHour()->toImmutable();
    $handle = launchExecutorProcess(function (Payload $input) use ($deadline): Generator {
        yield Steps::activity('original', fn(): array => ['recorded' => true]);
        yield Steps::waitUntil('pause', $deadline);

        return ['finished' => true];
    });

    app(Executor::class)->execute($handle);

    expect(ProcessInstance::query()->sole()->status)->toBe('suspended');

    app()->bind(ExecutorProcess::class, fn(): ExecutorProcess => new ExecutorProcess(
        fn(Payload $input): array => ['returned' => 'too early'],
    ));
    ProcessInstance::query()->whereKey($handle->id())->update(['status' => 'pending']);

    app(Executor::class)->execute($handle);

    $instance = ProcessInstance::query()->sole();

    expect($instance->status)->toBe('failed')
        ->and($instance->active)->toBeTrue()
        ->and($instance->result)->toBeNull()
        ->and($instance->claim_token)->toBeNull()
        ->and($instance->claim_expires_at)->toBeNull()
        ->and(JournalEntry::query()->where('entry_type', 'process_error')->count())->toBe(1);
});

it('preserves successful activities and rolls back the failed activity before operator recovery', function (string $exceptionClass): void {
    createExecutorEffectsTable();
    $firstCalls = 0;
    $secondCalls = 0;
    $shouldFail = true;
    $handle = launchExecutorProcess(function (Payload $input) use (&$firstCalls, &$secondCalls, &$shouldFail, $exceptionClass): Generator {
        yield Steps::activity('first', function () use (&$firstCalls): array {
            $firstCalls++;
            DB::table('executor_effects')->insert(['name' => 'first']);

            return ['first' => true];
        });
        yield Steps::activity('second', function () use (&$secondCalls, &$shouldFail, $exceptionClass): array {
            $secondCalls++;
            DB::table('executor_effects')->insert(['name' => 'second']);

            if ($shouldFail) {
                throw new $exceptionClass('Activity failed');
            }

            return ['second' => true];
        });

        return ['finished' => true];
    });

    app(Executor::class)->execute($handle);

    $instance = ProcessInstance::query()->sole();

    expect($instance->status)->toBe('failed')
        ->and($instance->active)->toBeTrue()
        ->and($instance->claim_token)->toBeNull()
        ->and($instance->claim_expires_at)->toBeNull()
        ->and(DB::table('executor_effects')->pluck('name')->all())->toBe(['first'])
        ->and(JournalEntry::query()->where('entry_type', 'activity')->pluck('step_key')->all())->toBe(['first']);

    $first = JournalEntry::query()->where('entry_type', 'activity')->sole();
    $error = JournalEntry::query()->where('entry_type', 'activity_error')->sole();
    $original = $error->getRawOriginal();

    expect($error->step_key)->toBe('second')
        ->and(json_encode($error->payload, JSON_THROW_ON_ERROR))->toContain('Activity failed');

    $shouldFail = false;
    $instance->update(['status' => 'pending']);

    app(Executor::class)->execute($handle);

    expect($instance->fresh()->status)->toBe('completed')
        ->and($instance->fresh()->result)->toBe(['finished' => true])
        ->and($firstCalls)->toBe(1)
        ->and($secondCalls)->toBe(2)
        ->and(DB::table('executor_effects')->orderBy('name')->pluck('name')->all())->toBe(['first', 'second'])
        ->and(JournalEntry::query()->where('entry_type', 'activity')->where('step_key', 'first')->sole()->id)->toBe($first->id)
        ->and($error->fresh()->getRawOriginal())->toBe($original);
})->with(['exception' => [RuntimeException::class], 'PHP error' => [TypeError::class]]);

it('records process errors outside activities and ignores those informational entries on recovery', function (): void {
    $calls = 0;
    $shouldFail = true;
    $handle = launchExecutorProcess(function (Payload $input) use (&$calls, &$shouldFail): Generator {
        $value = yield Steps::activity('first', function () use (&$calls): array {
            $calls++;

            return ['first' => true];
        });

        if ($shouldFail) {
            throw new RuntimeException('Process body failed');
        }

        return $value;
    });

    app(Executor::class)->execute($handle);

    $instance = ProcessInstance::query()->sole();

    expect($instance->status)->toBe('failed')
        ->and($instance->active)->toBeTrue()
        ->and($instance->claim_token)->toBeNull()
        ->and($instance->claim_expires_at)->toBeNull()
        ->and(JournalEntry::query()->where('entry_type', 'activity')->count())->toBe(1);

    $error = JournalEntry::query()->where('entry_type', 'process_error')->sole();

    expect(json_encode($error->payload, JSON_THROW_ON_ERROR))->toContain('Process body failed');

    $shouldFail = false;
    $instance->update(['status' => 'pending']);

    app(Executor::class)->execute($handle);

    expect($instance->fresh()->status)->toBe('completed')
        ->and($instance->fresh()->result)->toBe(['first' => true])
        ->and($calls)->toBe(1)
        ->and(JournalEntry::query()->where('entry_type', 'process_error')->count())->toBe(1);
});

it('rolls back activity writes when the returned value cannot be serialized', function (): void {
    createExecutorEffectsTable();
    $resource = fopen('php://memory', 'r+');
    $handle = launchExecutorProcess(function (Payload $input) use ($resource): Generator {
        yield Steps::activity('unsupported-result', function () use ($resource): mixed {
            DB::table('executor_effects')->insert(['name' => 'unrecorded']);

            return $resource;
        });

        return ['finished' => true];
    });

    try {
        app(Executor::class)->execute($handle);
    } finally {
        fclose($resource);
    }

    $instance = ProcessInstance::query()->sole();

    expect($instance->status)->toBe('failed')
        ->and($instance->active)->toBeTrue()
        ->and($instance->claim_token)->toBeNull()
        ->and($instance->claim_expires_at)->toBeNull()
        ->and(DB::table('executor_effects')->count())->toBe(0)
        ->and(JournalEntry::query()->where('entry_type', 'activity')->count())->toBe(0)
        ->and(JournalEntry::query()->whereIn('entry_type', ['activity_error', 'process_error'])->count())->toBe(1);
});

it('rolls back activity writes when inserting its success entry fails', function (): void {
    createExecutorEffectsTable();
    $handle = launchExecutorProcess(function (Payload $input): Generator {
        yield Steps::activity('persist-result', function (): array {
            DB::table('executor_effects')->insert(['name' => 'unrecorded']);

            return ['finished' => true];
        });

        return ['finished' => true];
    });
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER reject_executor_activity
        BEFORE INSERT ON process_journal_entry
        WHEN NEW.entry_type = 'activity' AND NEW.step_key = 'persist-result'
        BEGIN
            SELECT RAISE(ABORT, 'Journal write rejected');
        END
        SQL);

    app(Executor::class)->execute($handle);

    $instance = ProcessInstance::query()->sole();

    expect($instance->status)->toBe('failed')
        ->and($instance->claim_token)->toBeNull()
        ->and($instance->claim_expires_at)->toBeNull()
        ->and(DB::table('executor_effects')->count())->toBe(0)
        ->and(JournalEntry::query()->where('entry_type', 'activity')->count())->toBe(0)
        ->and(JournalEntry::query()->whereIn('entry_type', ['activity_error', 'process_error'])->count())->toBe(1);
});

it('marks the instance failed after the execution transaction rolls back', function (): void {
    createExecutorEffectsTable();
    $handle = launchExecutorProcess(function (Payload $input): Generator {
        yield Steps::activity('first', function (): array {
            DB::table('executor_effects')->insert(['name' => 'first']);

            return ['first' => true];
        });
        yield Steps::activity('second', fn(): never => throw new RuntimeException('Activity failed'));

        return ['finished' => true];
    });
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER reject_executor_error
        BEFORE INSERT ON process_journal_entry
        WHEN NEW.entry_type IN ('activity_error', 'process_error')
        BEGIN
            SELECT RAISE(ABORT, 'Error journal unavailable');
        END
        SQL);

    app(Executor::class)->execute($handle);

    $instance = ProcessInstance::query()->sole();

    expect($instance->status)->toBe('failed')
        ->and($instance->active)->toBeTrue()
        ->and($instance->claim_token)->toBeNull()
        ->and($instance->claim_expires_at)->toBeNull()
        ->and(DB::table('executor_effects')->count())->toBe(0)
        ->and(JournalEntry::query()->sole()->entry_type)->toBe('launch')
        ->and(DB::connection()->transactionLevel())->toBe(0);
});
