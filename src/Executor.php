<?php

declare(strict_types=1);

namespace Chronicle\Process;

use Chronicle\Process\Data\ExecutionSummary;
use Chronicle\Process\Data\ProcessHandle;
use Chronicle\Process\Enums\EntryType;
use Chronicle\Process\Enums\ExecutionOutcome;
use Chronicle\Process\Models\JournalEntry;
use Chronicle\Process\Models\ProcessInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Executor
{
    public function execute(ProcessHandle $handle): ExecutionSummary
    {
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
        DB::connection()->transaction(function () use ($handle, $claim) {
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

            // load the processes' non-signal, non activity_error, non process_error journal entries in ascending order by sequence.
            $replayEntries = JournalEntry::query()
                ->where('process_instance_id', $instance->id)
                ->whereNotIn('entry_type', [EntryType::ActivityError, EntryType::ProcessError, EntryType::Signal])
                ->orderBy('sequence', 'asc')
                ->get();

            // load the processes' signals in ascending order in a separate collection. this is our "inbox"
            $signalInbox = JournalEntry::query()
                ->where('process_instance_id', $instance->id)
                ->where('entry_type', EntryType::Signal)
                ->orderBy('id', 'asc')
                ->get();
        });
        //
        // for now, activity and process error entries are treated as informational logs.
        //
        // create an instance of the process, use a process registry to locate the class. Use laravel
        // app(class) to create the instance such that the processes' constructor can use dependency injection.
        //
        // the first entry should be the launch entry_type, that payload should be passed to the run method
        // along with an instance of the Steps helper. If the first entry is not a launch entry_type, there is
        // a severe bug in the framework, the executor should fail loudly.
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
