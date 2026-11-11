<?php

declare(strict_types=1);

namespace Chronicle\Process;

use Chronicle\Process\Contracts\Payload;
use Chronicle\Process\Contracts\Process;
use Chronicle\Process\Data\ArrayPayload;
use Chronicle\Process\Data\CommonScalarPayload;
use Chronicle\Process\Data\ExceptionPayload;
use Chronicle\Process\Data\ExecutionSummary;
use Chronicle\Process\Data\ProcessHandle;
use Chronicle\Process\Data\WaitPayload;
use Chronicle\Process\Enums\EntryType;
use Chronicle\Process\Enums\ExecutionOutcome;
use Chronicle\Process\Exceptions\CorruptJournalException;
use Chronicle\Process\Exceptions\InvalidStepException;
use Chronicle\Process\Exceptions\ReplayException;
use Chronicle\Process\Models\JournalEntry;
use Chronicle\Process\Models\ProcessInstance;
use Chronicle\Process\Steps\ActivityStep;
use Chronicle\Process\Steps\AwaitSignalStep;
use Chronicle\Process\Steps\Step;
use Chronicle\Process\Steps\WaitUntilStep;
use Generator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class Executor
{
    public function __construct(
        private Chronicle $chronicle,
        private Steps $steps,
    ) {}

    public function execute(ProcessHandle $handle): ExecutionSummary
    {
        $process = $this->chronicle->resolveProcess($handle->type());

        // claim instance by generating a uuid
        $claim = Str::uuid()->toString();
        // do a conditional update on process instance where claim token is missing, active is true and status pending
        // and process instance equals the one on the handle. Attempt to set the claim
        // token to the generated uuid, the status to running, and the claim expiry as
        // now() + some fixed amount such as 5 minutes for now the expiry will be configured more in the future.
        $claimed = ProcessInstance::query()
            ->where('id', $handle->id)
            ->whereNull('claim_token')
            ->where('active', true)
            ->where('status', 'pending')
            ->update([
                'claim_token' => $claim,
                'claim_expires_at' => now()->addMinutes(5),
                'status' => 'running',
            ]);

        if (! $claim) {
            return new ExecutionSummary($handle->id, ExecutionOutcome::Skipped);
        }

        //
        // now start a transaction, do a read on the process instance again only selecting
        // a row matching the instance and with our claim token, lock the row for update.
        return DB::connection()->transaction(function () use ($handle, $claim, $process) {
            $instance = null;

            try {
                $instance = ProcessInstance::query()
                    ->where('id', $handle->id)
                    ->where('claim_token', $claim)
                    ->lockForUpdate()
                    ->first();

                // observe the result count, if 0, someone else stole the claim, we should not proceed.
                // use similar execution summary as above to indicate that we effectively did nothing.
                if (! $instance) {
                    return new ExecutionSummary($handle->id, ExecutionOutcome::Skipped);
                }

                return $this->executeInTransaction($instance, $claim, $process);
            } catch (Throwable $e) {
                JournalEntry::query()->create([
                    'process_instance_id' => $handle->id(),
                    'description' => "Exception caught while executing process",
                    'entry_type' => EntryType::ProcessError,
                    'payload_type' => ExceptionPayload::TYPE,
                    'payload' => ExceptionPayload::make($e),
                    'step_key' => 'process_error',
                ]);

                if ($instance) {
                    $instance->claim_token = null;
                    $instance->claim_expires_at = null;
                    $instance->suspended_until = null;
                    $instance->suspended_for_signals = null;
                    $instance->status = 'failed';
                    $instance->save();
                }

                return new ExecutionSummary($instance->id, ExecutionOutcome::Failed, $e);

            }
        });
    }

    /**
     * @param  Process<Payload>  $process
     */
    public function executeInTransaction(ProcessInstance $instance, string $claim, Process $process): ExecutionSummary
    {
        // load the processes' non-signal, non activity_error, non process_error journal entries in ascending order by sequence.
        /** @var Collection<int, JournalEntry> $replayEntries */
        $replayEntries = JournalEntry::query()
            ->where('process_instance_id', $instance->id)
            ->whereNotIn('entry_type', [EntryType::ActivityError, EntryType::ProcessError, EntryType::Signal])
            ->orderBy('sequence', 'asc')
            ->get();

        // load the processes' signals in ascending order in a separate collection. this is our "inbox"
        /** @var Collection<int, JournalEntry> $signalInbox */
        $signalInbox = JournalEntry::query()
            ->where('process_instance_id', $instance->id)
            ->where('entry_type', EntryType::Signal)
            ->orderBy('id', 'asc')
            ->get();

        /** @var JournalEntry|null $launchEntry- */
        $launchEntry = $replayEntries->shift();

        if (! $launchEntry || $launchEntry->entry_type !== EntryType::Launch) {
            throw new CorruptJournalException("Process Instance {$instance->id} is missing a Launch entry");
        }

        $launchPayload = $this->chronicle->deserializePayload($launchEntry->payload_type, $launchEntry->payload);
        $outcome = $process->run($launchPayload, $this->steps);
        $sequence = 0;
        $result = null;

        if ($outcome instanceof Generator) {
            while ($outcome->valid()) {
                /** @var JournalEntry|null $entry- */
                $entry = $replayEntries->shift();
                $step = $outcome->current();

                if (! $step instanceof Step) {
                    throw InvalidStepException::notAStep($instance->id);
                }

                if ($entry) {
                    // replaying
                    $sequence = $entry->sequence;

                    if ($step->stepKey !== $entry->step_key) {
                        throw ReplayException::stepMismatch($instance->id, $entry->step_key, $step->stepKey);
                    }

                    if ($step->entryType !== $entry->entry_type) {
                        throw ReplayException::stepEntryTypeMismatch($instance->id, $entry->entry_type->value, $step->entryType->value);
                    }

                    if ($step->entryType === EntryType::Activity) {
                        /** @var Payload|ArrayPayload|CommonScalarPayload $payload */
                        $payload = $this->chronicle->deserializePayload($entry->payload_type, $entry->payload);
                        $outcome->send(method_exists($payload, 'value') ? $payload->value() : $payload);
                    } elseif ($step->entryType === EntryType::Wait) {
                        $waitPayload = WaitPayload::fromPayload($entry->payload);

                        if ($waitPayload->hasPassed(now())) {
                            $outcome->send(null);
                        } else {
                            $instance->suspended_until = $waitPayload->until;
                            $instance->status = 'suspended';
                            $instance->claim_token = null;
                            $instance->claim_expires_at = null;
                            $instance->save();

                            return new ExecutionSummary($instance->id, ExecutionOutcome::Suspended);
                        }
                    } elseif ($step->entryType === EntryType::SignalWait && $step instanceof AwaitSignalStep) {
                        $signal = $signalInbox->firstWhere('consumed_by', $entry->id);

                        if (! $signal) {
                            $signal = $signalInbox->where('entry_type', EntryType::Signal->value)
                                ->where('step_key', $step->signalName)
                                ->whereNull('consumed_by')
                                ->first();
                        }

                        if ($signal) {
                            if (! $signal->consumed_by) {
                                $signal->consumed_by = $entry->id;
                                $signal->save();
                            }

                            $outcome->send($signal);
                        } else {
                            $instance->suspended_until = null;
                            $instance->suspended_for_signals = [$step->signalName];
                            $instance->status = 'suspended';
                            $instance->claim_token = null;
                            $instance->claim_expires_at = null;
                            $instance->save();

                            return new ExecutionSummary($instance->id, ExecutionOutcome::Suspended);
                        }
                    }
                } else {
                    // not replaying
                    $sequence++;

                    if ($step instanceof ActivityStep) {
                        $res = null;

                        try {
                            $res = DB::connection()->transaction(function () use ($step, $instance, $sequence) {
                                $activityResult = ($step->callback)();
                                $activityResultPayload = $this->chronicle->toPayload($activityResult);

                                JournalEntry::query()->create([
                                    'process_instance_id' => $instance->id,
                                    'description' => $step->description,
                                    'payload' => $activityResultPayload->jsonSerialize(),
                                    'payload_type' => $activityResultPayload->type(),
                                    'step_key' => $step->stepKey,
                                    'entry_type' => EntryType::Activity,
                                    'sequence' => $sequence,
                                ]);

                                return $activityResult;
                            });
                        } catch (Throwable $e) {
                            JournalEntry::query()->create([
                                'process_instance_id' => $instance->id,
                                'description' => "Exception caught for activity {$step->stepKey}",
                                'step_key' => $step->stepKey,
                                'payload_type' => ExceptionPayload::TYPE,
                                'payload' => ExceptionPayload::make($e)->jsonSerialize(),
                                'entry_type' => EntryType::ActivityError,
                            ]);

                            // TODO - retry policies - should check for a policy and set
                            // suspended_until  so the process can retry

                            $instance->claim_token = null;
                            $instance->claim_expires_at = null;
                            $instance->suspended_until = null;
                            $instance->suspended_for_signals = null;
                            $instance->status = 'failed';
                            $instance->save();

                            return new ExecutionSummary($instance->id, ExecutionOutcome::Failed, $e);
                        }

                        try {
                            $outcome->send($res);
                        } catch (Throwable $e) {
                            JournalEntry::query()->create([
                                'process_instance_id' => $instance->id,
                                'description' => "Exception caught while executing process",
                                'step_key' => $step->stepKey,
                                'entry_type' => EntryType::ProcessError,
                                'payload_type' => ExceptionPayload::TYPE,
                                'payload' => ExceptionPayload::make($e)->jsonSerialize(),
                            ]);

                            $instance->claim_token = null;
                            $instance->claim_expires_at = null;
                            $instance->suspended_until = null;
                            $instance->suspended_for_signals = null;
                            $instance->status = 'failed';
                            $instance->save();

                            return new ExecutionSummary($instance->id, ExecutionOutcome::Failed, $e);
                        }
                    } elseif ($step instanceof WaitUntilStep) {
                        $waitPayload = WaitPayload::make($step->until);

                        JournalEntry::query()->create([
                            'process_instance_id' => $instance->id,
                            'description' => $step->description,
                            'payload' => $waitPayload->jsonSerialize(),
                            'payload_type' => $waitPayload->type(),
                            'step_key' => $step->stepKey,
                            'entry_type' => EntryType::Wait,
                            'sequence' => $sequence,

                        ]);

                        if (! $waitPayload->hasPassed(now())) {
                            $instance->claim_token = null;
                            $instance->claim_expires_at = null;
                            $instance->status = 'suspended';
                            $instance->suspended_until = $waitPayload->until;
                            $instance->save();

                            return new ExecutionSummary($instance->id, ExecutionOutcome::Suspended);
                        } else {
                            $outcome->send(null);
                        }
                    } elseif ($step instanceof AwaitSignalStep) {
                        $entry = JournalEntry::query()->create([
                            'process_instance_id' => $instance->id,
                            'description' => $step->description,
                            'step_key' => $step->stepKey,
                            'entry_type' => EntryType::SignalWait,
                            'sequence' => $sequence,
                        ]);

                        $signal = $signalInbox->where('entry_type', EntryType::Signal->value)
                            ->where('step_key', $step->signalName)
                            ->whereNull('consumed_by')
                            ->first();

                        if ($signal) {
                            $signal->consumed_by = $entry->id;
                            $signal->save();
                            $outcome->send($signal);
                        } else {
                            $instance->suspended_until = null;
                            $instance->suspended_for_signals = [$step->signalName];
                            $instance->status = 'suspended';
                            $instance->claim_token = null;
                            $instance->claim_expires_at = null;
                            $instance->save();

                            return new ExecutionSummary($instance->id, ExecutionOutcome::Suspended);
                        }
                    } else {
                        throw InvalidStepException::unknownStep($instance->id, $step);
                    }
                }
            }

            $result = $outcome->getReturn();
        } else {
            $result = $outcome;
        }

        $instance->claim_token = null;
        $instance->claim_expires_at = null;
        $instance->active = false;
        $instance->status = 'completed';
        $instance->result = $this->chronicle->serializePayload($result);
        $instance->save();

        return new ExecutionSummary($instance->id, ExecutionOutcome::Completed, $result);
        //
        // for now, activity and process error entries are treated as informational logs.
        //
        //
        // when/if the run call yields, check the yielded step descriptor, if the step_key matches:
        //   and its an activity - use the payload from the entry and do not invoke the callback. use a (TBD) payload registry
        //      to deserialize the payload into its corresponding class. TBD, we will have support for common scalars like int,
        //      string, datetime (Carbon or others), maybe UUID, etc... so that process author's do not have to write dumb
        //      wrappers around those common types - their activities can just return a Carbon, or a string, or a UUID. We
        //      will reserve forcing the adoption of Payload to more complicated types.
        //   and its a wait, check if the current time equals or greater than the "until" recorded in the internal wait payload associated with the entry.
        //      if not hangup, otherwise continue
        //   and its a wait signal, check if a matching signal exists in the journal, if so continue, otherwise hangup
        //      for signals check if the wait signal entry is already associated with a signal (consumed by)
        //      if the wait is not associated with any signals, but eligible signals exist, update the signals
        //      consumed by field to the id of the wait signal entry
        //
        // if there is a journal entry / step mimatch, throw a ReplayException.
        //
        // when there are no more journal entries, any yield step descriptor should create a journal entry.
        // For activities this means actually calling the callback and serializing the payload. Again, common scalars
        // will get special support so that process author's do not have dumb boilerplate to write.
        //
        // for wait and signal wait activities, we will have an internal payload type for them, so that we can
        // record when they are waiting until or what signals they are waiting for. If a new wait activity returns
        // an until that has already passed, just continue, that's weird but hey. If a new signal wait activity
        // indiciates it is waiting for a signal, and we have a matching unconsumed signal in the inbox, mark the
        // signal as consumed and continue without hanging up.
        //
        // when the process stops yielding and instead returns its result payload, the process is complete
        // the process can be marked as status completed and active false. an ExecutionSummary should be
        // returned with the result of the process. The result should be saved to process_instance.result field.
        // The claim fields should be cleared.
        // The transaction should be committed, ensure that the above updates and journal entries are recorded.
        //
        // if the execution ends due to blcoking because of waiting, must remain active and status goes to suspended.
        // if waiting until, that can be put into the suspended_until field of the process.
        // the suspended_for_signals can be populated if waiting for signals.
        // The claim fields should be cleared
        // Transaction should be committed ensure that these changes become visible and the lock is released.
        //
        // if at any point the process returns its result but there is still un-replayed journal entries, throw
        // a ReplayException.
        //
        // We must implement a three tier error handling strategy.
        //  - within the transaction, while executing an activity callback,
        //      we will wrap the activity callback in a another transaction (which becomes a savepoint)
        //      if an exception occurs (ex remote service failure)
        //      wrap the exception in a TBD exception called ActivityException and rethrow
        //      the savepoint will be rolled back at this point, meaning that any database within the activity
        //      callback is rolled back. Process/activity authors will need to be aware of idempotent behavior required
        //      when interacting with remote services. The save point should encompass calling the callback, serializing
        //      the result, and inserting the journal entry.
        //
        //  - within the whole scope of the transaction - if we catch an ActivityException, create an entry for activity_error
        //      otherwise create an entry for process_error. Mark the process instance as failed, clear the claims fields.
        //      commit transaction. This way any activity that we could record is, and the error is recorded too.
        //
        //  - immediately outside the transaction. catching an exception here means the transaction likely rolled back and
        //      could not be committed. The process status was not updated. We should attempt to at least put the process
        //      into a failed status. Log the exception in the application logs. Use a conditional write using our claim
        //      and status = running to set the status to failed and clear the claims. If the transaction did actually
        //      complete, then the status will not be running anymore and so the conditional write will have no effect.
        //
        //  There is more to do with errors - namely retry policies and recovery. I want to defer on that for now.
        //
        //
        // if a ReplayException is thrown, the instance should be marked as failed with the claim token and
        // claim expiry cleared. the process should remain active. the sys operator will need to make a fix
        // and then can flip any failed processes back to pending or suspended.
    }
}
