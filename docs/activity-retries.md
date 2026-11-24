Activity Error Handling & Retry Design 
========================================

## Overview

Error handling is a first class concern in Chronicle. Chronicle processes can automatically retry and recover from retry-able errors encountered during activity invocations.

Chronicle also provides a way to resume failed processes.

## Process API

```php
final class SomeProcess implements Process
{

    public function run(Payload $launchPayload, Steps $steps): mixed
    {
        $result = yield $steps->activity(
            'some-activity',
            function () {
                // You can have your activity categorize its own exceptions by throwing
                // exceptions that implement the HasRetryStatus contract.

                // Alternatively you can use the retriableExceptions[] list to enumerate
                // which exceptions can be retried.
            },
        )->retryPolicy(
            maxAttempts: 4 // 1 initial attempt + 3 retries.
            // the number of millis after a failed attempt before the next attempt 
            backOffMillis: [1000, 5000, 25_000] ,
            retryableExceptions: [SomeException::class],
            retryByDefault: false, // if an exception is not identified as retriable or non-retriable - should we retry it?
            jitterMillis: 500, // the max jitter to apply to a retry. chosen randomly such that 0 <= ACTUAL JITTER <= MAX_JITTER
        );

        return 'DONE';
    }
}
```

## Attempts and the Process Journal

How are retries recorded into the journal? We will add a nullable ```attempt``` column to the journal entry table.

The following is an example of what the entry table could look like. The explanation column below is not a column in the process journal entry table.

| Entry Type      | Step Key | Sequence | Attempt| Explanation |
| --------------- | -------- | -------- | ------ | ----------- |
| launch          |launch    | 0        | - | records the launch payload, no change |
| activity_error  |a1        | 1 | 1 | first attempt resulted in an error |
| activity_error  |a1        | 1 | 2 | 2nd attempt resulted in an error |
| activity        |a1        | 1 | 3 | 3rd attempt succeeded |
| wait            |          | 2 | - | process continued as normal from then on. in this case it enters a wait. |
| activity_error  |a2        | 3 | 1 | Another activity, this one fails too |
| activity_error  |a2        | 3 | 2 | And again.... |
| activity_error  |a2        | 3 | 3 | And again... |
| activity_failure|a2        | 3 | - | That was the last retry, log activty failure in the journal |
| resume          |          | - | - | Operator sends the resume signal. |
| activity        |a2        | 4 | 1 | We start a new attempt sequence for a2 and it succeeds |


## Executor Behavior

### During Execute (non-replay)

Precedence for determining if the exception can be retried:
 - If the exception thrown by the activity implements the HasRetryStatus interface, then ```isRetryable()``` on the exception will be used.
 - Else if ```retryableExceptions``` is populated, check if the thrown exception is in it.
 - Else if ```retryableExceptions``` is not populated but retryByDefault is true, then retry
 - Else, do not retry
 - Note: a non-null, non-empty ```retryableExceptions``` and ```retryByDefault = true``` is not allowed. If detected a non-retryable chronicle exception shall be thrown.

If the executor catches a retry-able exception (isRetryable() returns true), the executor must:
 - Locate the largest attempt number in the process journal where:
   - entry_type is activity_error
   - sequence matches the current activity's
   - step_key matches the current activity's
 - That number represents how many prior attempts have been made.
 - Record a new activity_error entry in the process journal with attempt as prior attempts + 1.
 - If:
    - Attempt  < max attempts as defined by the activity's policy, THEN:
        - Set process status as suspended
        - Set suspended until as now() + backOffMillis[attempt - 1] + random_in_range(0, jitterMillis)
        - Clear execution claim fields
    - Else:
        - Record a 'activity_failure' journal entry using the same sequence and entry key as the activity.
        - Set process status as 'failed'
        - Clear execution claims.
If the executor catches a non-retryable exception, the executor must:
 - Record an activity_error in the process journal
 - Set the process status to failed
 - Set suspended_until and suspended_for_signals to null
 - Set the execution claim fields to null

### During Replay

- The replay entries read from the journal must include the activity_failure and process_failure entries. They must not include the ```resume``` signals.
- A journal entry of type process_failure is expected to not match the step entry so do not fail automatically on that. If during replay a process_failure journal entry is encountered:
    - Check for a resume signal whose consumed by field matches the process_failure entry id:
        - If found, then allow the replay loop to continue but do not call send or next on the generator
        - Otherwise, return execution summary of failed and ensure the process status returns to failed as well as clearing of the execution claims.
- An journal entry type and step entry type mismatch is ok if the step is an activity and the journal entry type is activity_failure. (ie, don't throw ReplayException)
- If the journal entry type is activity failure and the step entry type is activity, THEN:
    - Check for a resume signal whose consumed_by field matches the current activity_failure entry id.
    - If it doesn't exist, do not try to locate an unconsumed resume signal.
    - If a resume signal is found:
        - Allow the replay loop to continue BUT DO NOT call send or next on the generator
        - This will allow the executor to enter the "execution" path on the next loop iteration.
        - Since we avoided calling send() or next on the generator, the "execution" path will be able
            to invoke the activity again. It will also move to the next sequence number.
    - If no signal is found, then the process cannot continue, the activity retries were exhausted and
        the operator has not indicated that the process can continue. Return ExecutionSummary of Failed and ensure
        the process status is returned to ```failed``` as we as clearing the execution claim fields.

### Scheduler Behavior

Because the executor takes care to set suspended_until for retryable exceptions, the current scheduler logic will
automatically pick up processes when they are eligible for retry.

## Resuming

It's common for dependency outages to occur. These outages may span longer than a process is willing to retry for causing it to enter
the ```failed``` state. We can't simply set the process back to suspended or pending, once the executor picks it back up it is going to replay and conclude that the attempts are exhausted and fail the process again.

Thus, we need to record the system/operator's intent to resume the process in the process journal. This way the executor can give the activity a fresh set of attempts. Resume is intended to allow a process to resume from a process_failure
or an activity_failure.

We introduce a new entry_type ```resume```. Resumes functions like a signal.

```php
final class Chronicle
{
    // ...

    public function resume(string $instanceId);
}
```

The resume method:
 - Acquires an update lock on the process instance
 - Asserts that the process is failed and the last (non-signal) journal entry is activity_failure or process_failure, and that no resume signal was consumed by that journal entry.
 - Inserts a resume journal entry with consumed_by = the last activity_failure or process_failure 
 - Set the process status to ```suspended```
