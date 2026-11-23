Activity Error Handling & Retry Design 
========================================

## Overview

Error handling is a first class concern in Chronicle. Chronicle processes can automatically retry and recover from retriable errors encountered during activity invocations.

## Process API

```php
final class SomeProcess implements Process
{

    public function run(Payload $launchPayload, Steps $steps): mixed
    {
        $result = yield $steps->activity(
            'some-activity',
            function () {
                // You can have your activity categorize its own exceptions
                // by rethrowing them as ChronicleRetriableException or
                // ChronicleNonRetriableException.

                // Alternatively you can use the retriableExceptions[] list to enumerate
                // which exceptions are retriable.
            },
        )->retryPolicy(
            maxAttempts: 4 // 1 initial attempt + 3 retries.
            // the number of millis after a failed attempt before the next attempt 
            backOffMillis: [1000, 5000, 25_000] ,
            retriableExceptions: [SomeException::class],
            retryByDefault: false, // if an exception is not identified as retriable or non-retriable - should we retry it?
            jitterMillis: 500, // the max jitter to apply to a retry. chosen randomly such that 0 < ACTUAL JITTER <= MAX_JITTER
        );

        return 'DONE';
    }
}
```

## Attempts and the Process Journal

How are retries recorded into the journal? We will add a nullable ```attempt``` column to the journal entry table.

The following is an example of what the entry table could look like. The explanation table is added but not an
actual column in the process journal entry table.

| Entry Type | Sequence | Attempt| Explanation |
| --------------- | --------------- | --------------- | --------------- |
| launch         | 0 | - | records the launch payload, no change |
| activity_error | 1 | 1 | first attempt resulted in an error |
| activity_error | 1 | 2 | 2nd attempt resulted in an error |
| activity       | 1 | 3 | 3rd attempt succeeded |
| wait           | 2 | - | process continued as normal from then on. in this case it enters a wait. |
| activity_error | 2 | 1 | Another activity, this one fails too |
| activity_error | 2 | 2 | And again.... |
| activity_error | 2 | 3 | And it exhausts its retry. Process is now in an error status. |
| resume         | - | - | Operator sends the resume signal. |

## Process Status and Scheduling

### Retriable Errors

If the executor catches a retriable exception (isRetriable() returns true), the executor must:
 - Locate the largest attempt number in the process journal where:
   - entry_type is activity_error
   - sequence matches the current activity's
   - step_key matches the current activity's
 - That number represents how many prior attempts have been made.
 - Record a new activity_error entry in the process journal with attempt as prior attempts + 1.
 - If:
    - Prior attempts + 1 < max attempts as defined by the activity's policy, THEN:
        - Set process status as suspended
        - Set suspended until as now() + backOffMillis[prior attempts + 1] + random_in_range(0, jitterMillis)
        - Clear execution claim fields
    - Else:
        - Set process status as error
        - Clear execution claims.

### NonRetriable Errors

Similar to above - the executor should record the activity_error in the process journal. Then the process status
should be immediately set to error, with nulled suspended_until, suspended_for_signals and execution claims.

### Scheduling 

Because of how the executor takes care to set suspended_until for retriable exceptions, the current scheduler logic will
automatically pick up processes when they are eligible for retry.

## Resuming

It's common for dependency outages to occur. These outages may span longer than a process is willing to retry for causing it to enter
the ```error``` state. We can't simply set the process back to suspended or pending, once the executor picks it back up it is going to replay and conclude that the attempts are exhausted and error the process again.

Thus, we need to record the system/operator's intent to resume the process in the process journal. This way the executor can give the activity a fresh set of attempts.

We introduce a new entry_type ```resume```. Resumes functions like a signal.
