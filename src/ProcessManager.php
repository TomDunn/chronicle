<?php

namespace Chronicle\Process;

use Chronicle\Process\Enums\EntryType;
use Chronicle\Process\Enums\InstanceStatus;
use Chronicle\Process\Models\HistoryEntry;
use Chronicle\Process\Models\ProcessInstance;
use Chronicle\Process\Steps\AwaitSignalStep;
use Chronicle\Process\Steps\SideEffectStep;
use Chronicle\Process\Steps\Step;
use Chronicle\Process\Steps\TimerStep;
use Generator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProcessManager
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    /**
    * @template TResult
    * @param Process<TResult> $process
    * @return TResult|InstanceStatus
    */
    public function run(Process $process): mixed
    {
        $upsert = ['name' => $process->name(), 'key' => $process->key()];
        DB::connection()->table('process_name_key')->upsert($upsert, ['name', 'key']);
        return DB::connection()->transaction(function () use ($process, $upsert) {
            DB::connection()->table('process_name_key')
                ->where($upsert)
                ->lockForUpdate()
                ->get();

            $instance = ProcessInstance::query()->where($upsert)->first();
            if (! $instance) {
                $instance = ProcessInstance::query()->create($upsert);
            }

            /** @var Collection<int, HistoryEntry> $history */
            $history = HistoryEntry::query()->where('process_instance_id', $instance->id)->orderBy('id', 'asc')->get();

            /** @var Generator<int, Step, mixed, scalar|array<mixed>|null> */
            $generator = $process->handle();
            $i = 0;

            while ($generator->valid()) {
                $step = $generator->current();

                $entry = $history->get($i);
                $activityResult = $entry?->payload;

                if ($entry == null || $entry->label !== $step->label) {
                    if ($step instanceof SideEffectStep) {
                        $activityResult = [
                            'value' => ($step->effect)(),
                        ];

                        HistoryEntry::query()->create([
                            'process_instance_id' => $instance->id,
                            'label' => $step->label,
                            'payload' => $activityResult,
                            'entry_type' => EntryType::Step,
                        ]);
                    }

                    if ($step instanceof TimerStep) {
                        $timerEntry = HistoryEntry::query()->create([
                            'process_instance_id' => $instance->id,
                            'label' => $step->label,
                            'entry_type' => EntryType::Step,
                        ]);

                        return new Hangup(
                            $timerEntry->recorded_at->add($step->interval)->toImmutable(),
                            InstanceStatus::AwaitingTimer
                        );
                    }

                    if ($step instanceof AwaitSignalStep) {
                        $timerEntry = HistoryEntry::query()->create([
                            'process_instance_id' => $instance->id,
                            'label' => $step->label,
                            'entry_type' => EntryType::Step,
                        ]);

                        return new Hangup(
                            $timerEntry->recorded_at->add($step->timeout)->toImmutable(),
                            InstanceStatus::AwaitingSignal,
                        );
                    }

                    if ($entry !== null && $entry->label !== $step->label) {
                        continue;
                    }
                }

                if ($step instanceof TimerStep) {
                    $eligibleAt = $entry->recorded_at->add($step->interval);
                    if (now()->isBefore($eligibleAt)) {
                        return new Hangup($eligibleAt->toImmutable(), InstanceStatus::AwaitingTimer);
                    } else {
                        $generator->next();
                    }
                } elseif ($step instanceof SideEffectStep) {
                    $generator->send($activityResult['value']);
                }

                $i++;
            }

            return $generator->getReturn();
        });
    }
}
