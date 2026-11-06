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

- Import `Chronicle\Process\Steps` to construct activities, deadline waits, and signal waits for process generators.
- Give every step a stable `stepKey`; use the optional `description` for operator-facing text.
- `Steps::activity()` accepts a closure without executing it. `Steps::waitUntil()` captures a `DateTimeInterface` deadline as an immutable value. `Steps::awaitSignal()` accepts one signal name.
- These helpers construct step objects only. Process execution, replay, and suspension for these objects are not yet wired into the engine.

## Rules, References, and Templates

Read before executing:

- `src/Steps.php` for factory signatures and `src/Steps/` for the returned step types
- `tests/Unit/StepsTest.php` for construction behavior and deadline handling

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

## Anti-patterns

- do not document package internals here; keep the skill focused on adoption in Laravel apps
- do not execute nondeterministic work before passing its closure to `Steps::activity()`
- do not expect these helpers to persist journal entries or execute a process
