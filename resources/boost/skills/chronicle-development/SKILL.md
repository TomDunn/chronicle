---
name: chronicle-development
description: >
  Configure and apply the Chronicle package in Laravel applications.
license: MIT
metadata:
  author: tomdunn
---

# Chronicle

Use this skill when a Laravel application needs to integrate the Chronicle package.

## Primary Goal

- apply the `tomdunn/chronicle` package's public API in the smallest correct way

## Workflow

### 1. Inspect the Laravel app context

- confirm the app is a Laravel project
- inspect the target code paths where the package should be applied

### 2. Apply the package's public API

- Import `Chronicle\Process\Facades\Chronicle` to register and launch process classes. The facade resolves the same singleton as `app(\Chronicle\Process\Chronicle::class)`, so both share registrations. Its class PHPDoc lists the public methods for IDE completion.
- Import `Chronicle\Process\Steps` to construct activities, deadline waits, and signal waits for process generators.
- Give every step a stable `stepKey`; use the optional `description` for operator-facing text.
- `Steps::activity()` accepts a closure without executing it. `Steps::waitUntil()` captures a `DateTimeInterface` deadline as an immutable value. `Steps::awaitSignal()` accepts one signal name.
- These helpers construct step objects only. Process execution, replay, and suspension for these objects are not yet wired into the engine.
- `Chronicle\Process\Data\ExecutionSummary` holds an instance ID, an `ExecutionOutcome`, an optional result, and an optional `Throwable`. The four outcomes are `Completed`, `Suspended`, `Failed`, and `Skipped`; they describe an attempt rather than the stored process status.
- A completed result can be `null`; check the outcome to identify completion. A failed outcome does not guarantee that a database failure allowed the failed status to be persisted.
- The summary type is available, but the current executor stub does not return it yet.
- `Chronicle\Process\Enums\EntryType` defines journal types: `Launch`, `Activity`, `Wait`, `SignalWait`, `ActivityError`, and `ProcessError`. Use a case's `->value` for its stored string, such as `EntryType::SignalWait->value` for `signal_wait`.

## Rules, References, and Templates

Read before executing:

- `src/Steps.php` for factory signatures and `src/Steps/` for the returned step types
- `src/Facades/Chronicle.php` for facade methods and `tests/Feature/ChronicleFacadeTest.php` for shared singleton behavior
- `tests/Unit/StepsTest.php` for construction behavior and deadline handling
- `src/Data/ExecutionSummary.php` and `src/Enums/ExecutionOutcome.php` for execution summary fields and outcomes
- `src/Enums/EntryType.php` for journal entry type values

## Examples

```php
use Chronicle\Process\Steps;

$activity = Steps::activity(
    'prepare-request',
    fn () => ['reference' => 'ref-123'],
    description: 'Prepare the approval request',
);

$wait = Steps::waitUntil('reminder-time', new DateTimeImmutable('2030-01-01T00:00:00Z'));
$signal = Steps::awaitSignal('approval-decision', 'approved');
```

In a consuming application test, assert that `$activity->stepKey` is `prepare-request` and that its callback has not run merely because the step was constructed.

```php
use Chronicle\Process\Data\ExecutionSummary;
use Chronicle\Process\Enums\ExecutionOutcome;

$summary = new ExecutionSummary(
    instanceId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    outcome: ExecutionOutcome::Completed,
    result: null,
);
```

## Anti-patterns

- do not document package internals here; keep the skill focused on adoption in Laravel apps
- do not execute nondeterministic work before passing its closure to `Steps::activity()`
- do not expect these helpers to persist journal entries or execute a process
