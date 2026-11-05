# Chronicle: Durable Workflow Execution API Design

> **Status:** Proposal & Architectural Guide  
> **Topic:** Process Contracts, Chronicle Facade, and Dual Execution APIs (Async vs. Sync-Until-Blocked)

---

## 1. Executive Summary & Philosophy

Chronicle is a durable workflow toolkit for Laravel focused on a clean, intuitive developer experience.

Its architecture mirrors the design of **Abacus** (`Faest\Abacus`), where developers define high-level contracts and interact with them through a single orchestrating facade:

| Layer | Abacus Architecture | Chronicle Architecture |
| :--- | :--- | :--- |
| **Domain Contract** | `Ledger` (state reducer, invariants, payload validation) | `Process` (workflow definition, generator steps, signals) |
| **Payload Layer** | `PayloadRegistry` (deserialization of typed payloads) | `PayloadRegistry` (typed process inputs, step values, signals) |
| **Orchestrator Facade** | `Abacus` (locks stream heads, records transactions, snapshots) | `Chronicle` (locks process keys, manages replay, runs engine) |
| **Interaction / Result** | `Transaction`, `OperationResult` | `ProcessHandle` (dual-mode execution outcome & reference) |

The central goal of Chronicle's execution API is to cleanly support two operational models:
1. **Asynchronous (Fire & Forget):** Dispatch to the Laravel queue worker and return a handle immediately.
2. **Synchronous-Until-Blocked ("Launch-and-Process" / "Signal-and-Process"):** The calling client immediately acquires the lock on the process instance and executes it inline in the caller's thread until the workflow either **completes** or **hits a suspension barrier** (`TimerStep`, `AwaitSignalStep`, or unready async activity).

This eliminates the need for clients to build complex polling infrastructure or WebSockets for fast-path workflows that finish in milliseconds, while retaining 100% durable fault tolerance if the workflow needs to wait.

---

## 2. Core Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                       Process Contract                       │
│   handle(): Generator, getProcessType(): string, key()       │
└──────────────────────────────┬───────────────────────────────┘
                               │ registered with
┌──────────────────────────────▼───────────────────────────────┐
│                       Chronicle Facade                       │
│    launch, run, signal, signalAndProcess, for(), of()        │
└──────────────┬───────────────────────────────┬───────────────┘
               │ async                         │ sync-until-blocked
┌──────────────▼─────────────┐   ┌─────────────▼───────────────┐
│     Queue Dispatcher       │   │       Inline Engine         │
│  Enqueues ProcessStepJob   │   │  Acquires lock, runs steps  │
└──────────────┬─────────────┘   └─────────────┬───────────────┘
               │                               │
               └───────────────┬───────────────┘
                               ▼
                 ┌───────────────────────────┐
                 │       ProcessHandle       │
                 │   status, value, hangup   │
                 └───────────────────────────┘
```

---

## 3. The Dual Execution Model

### A. Asynchronous (`launch`, `signal`)
- Writes the intent to the database:
  - For launches: creates the `ProcessInstance` (status: `Pending` or `Running`) and records initial parameters.
  - For signals: appends the signal to `process_instance_history_entry` as `EntryType::SignalReceived` (inbox).
- Enqueues a background job to the Laravel queue worker.
- Returns immediately with a `ProcessHandle` representing the pending/running instance.

### B. Synchronous-Until-Blocked (`launchAndProcess` / `run`, `signalAndProcess`)
- Atomically acquires the lock on the `(process_type, key)` or instance ID.
- Executes the generator inline within the caller’s database transaction and process.
- Replays recorded steps and executes new steps sequentially.
- If it encounters a wait barrier (`TimerStep` with future timestamp, or unsatisfied `AwaitSignalStep`):
  - Records the step entry and sets status to `Suspended` (`AwaitingTimer` or `AwaitingSignal`).
  - Commits history, schedules delayed jobs if applicable, and releases the lock.
  - **Returns a `ProcessHandle` with `isSuspended() === true` and the `Hangup` details.**
- If the generator returns:
  - Records completion entry with return value and sets status to `Completed` (`active = false`).
  - Commits history and releases the lock.
  - **Returns a `ProcessHandle` with `hasCompleted() === true` and `value()`.**

---

## 4. The API Surface

### 4.1 Registration & Resolution

Developers register process definitions and payloads with the service provider or facade (identical to Abacus ledger registration):

```php
use Chronicle\Process\Facades\Chronicle;

// In a ServiceProvider:
Chronicle::registerProcess(OrderFulfillmentProcess::class);
Chronicle::registerPayload('payment_captured', PaymentCapturedPayload::class);

// Dynamic resolution:
$processClass = Chronicle::resolveProcessClass('order-fulfillment');
```

---

### 4.2 Launching a Process

Chronicle supports direct execution verbs on the facade:

```php
// 1. Asynchronous Launch (Queued)
$handle = Chronicle::launch(
    process: OrderFulfillmentProcess::class,
    params: [$orderId],
    key: $orderKey,
);

// 2. Synchronous-Until-Blocked ("Launch-and-Process")
$handle = Chronicle::launchAndProcess(
    process: OrderFulfillmentProcess::class,
    params: [$orderId],
    key: $orderKey,
);

// Convenient alias for launchAndProcess:
$handle = Chronicle::run(OrderFulfillmentProcess::class, [$orderId], key: $orderKey);
```

---

### 4.3 Signaling an Existing Process

Signaling can target either an **Instance ID** (ULID) or a **Type + Key** pair:

#### Direct Facade Methods
```php
// Async signal by Instance ID
Chronicle::signal($instanceId, 'payment_received', $paymentData);

// Sync signal-and-process by Instance ID
$handle = Chronicle::signalAndProcess($instanceId, 'payment_received', $paymentData);

// Async signal by Type + Key
Chronicle::signalKey(OrderFulfillmentProcess::class, $orderKey, 'payment_received', $paymentData);

// Sync signal-and-process by Type + Key
$handle = Chronicle::signalKeyAndProcess(OrderFulfillmentProcess::class, $orderKey, 'payment_received', $paymentData);
```

#### Fluent Scoped Reference (`for` and `of`)
Callers can bind a reference to an instance before executing operations:

```php
// Target by instance ID:
$process = Chronicle::for($instanceId);

$process->signal('payment_received', $payload);            // Async
$handle = $process->signalAndProcess('payment_received', $payload); // Sync

// Target by Process Type and Domain Key:
$process = Chronicle::of(OrderFulfillmentProcess::class, key: $orderKey);

$process->signal('payment_received', $payload);            // Async
$handle = $process->signalAndProcess('payment_received', $payload); // Sync

// Querying the targeted process:
$status = $process->status();
$history = $process->history();
```

---

### 4.4 Querying Instances

```php
// Find by primary instance ID (ULID)
$handle = Chronicle::find(string $instanceId): ?ProcessHandle;

// Find active instance matching a domain key and process type
$handle = Chronicle::findActiveByKey(string $processType, string $key): ?ProcessHandle;

// Query all instances (active or terminated) sharing a key
$collection = Chronicle::queryByKey(string $key): Collection;
```

---

## 5. The Return Object: `ProcessHandle`

`ProcessHandle` provides a type-safe interface for inspecting execution results regardless of whether the execution was synchronous or asynchronous:

```php
namespace Chronicle\Process;

use Chronicle\Process\Enums\InstanceStatus;

class ProcessHandle
{
    public function id(): string;
    public function key(): ?string;
    public function type(): string;
    public function status(): InstanceStatus; // Pending, Running, Suspended, Completed, Failed

    public function hasCompleted(): bool;
    public function isSuspended(): bool;
    public function isRunning(): bool;

    /**
     * Final workflow return value.
     * Throws ProcessNotCompletedException if the process has not completed.
     */
    public function value(): mixed;

    /**
     * Return value or default fallback if suspended/running.
     */
    public function valueOr(mixed $default = null): mixed;

    /**
     * If suspended, returns the Hangup metadata (eligible_at, waiting signal label, etc.)
     */
    public function hangup(): ?Hangup;

    /**
     * Convenience methods for checking wait conditions:
     */
    public function isAwaitingSignal(?string $signal = null): bool;
    public function isAwaitingTimer(): bool;
}
```

---

## 6. Real-World Usage Patterns

### Scenario A: Fast-Path HTTP Controller (Checkout)

```php
class CheckoutController extends Controller
{
    public function store(CheckoutRequest $request)
    {
        // Launch and run synchronously inline
        $handle = Chronicle::launchAndProcess(
            process: OrderFulfillmentProcess::class,
            params: [$request->validated()],
            key: $request->cartId(),
        );

        // Path 1: Fast path — complete in 40ms without polling!
        if ($handle->hasCompleted()) {
            return response()->json([
                'status' => 'completed',
                'order' => $handle->value(),
            ]);
        }

        // Path 2: Suspended awaiting customer external redirect (e.g. 3D-Secure)
        if ($handle->isAwaitingSignal('3d_secure_approval')) {
            return redirect($handle->hangup()->meta('redirect_url'));
        }

        // Path 3: Suspended awaiting slow background tasks (warehouse, vendor API)
        return response()->json([
            'status' => 'processing',
            'process_id' => $handle->id(),
        ], 202);
    }
}
```

### Scenario B: Webhook Ingestion (Signal & Process Inline)

```php
class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $orderKey = $request->input('data.object.metadata.order_key');

        // Deliver signal and resume execution inline in the webhook request:
        $handle = Chronicle::of(OrderFulfillmentProcess::class, key: $orderKey)
            ->signalAndProcess('payment_received', $request->all());

        return response()->json([
            'acknowledged' => true,
            'completed' => $handle->hasCompleted(),
            'status' => $handle->status()->value,
        ]);
    }
}
```

---

## 7. Concurrency, Locking & Table Protocols

### A. Key Coordination Table (`process_name_key`)
To guarantee at most one active process instance per key:
1. `DB::table('process_name_key')->upsert(['name' => $type, 'key' => $key], ['name', 'key']);`
2. Obtain exclusive write lock inside transaction:
   ```php
   DB::table('process_name_key')
       ->where('name', $type)
       ->where('key', $key)
       ->lockForUpdate()
       ->get();
   ```

### B. Lock Contention During Synchronous Calls
When a client requests `launchAndProcess` or `signalAndProcess` but another worker or HTTP request currently holds the lock:

1. **Configurable Lock Timeout:** Support `overrideLockTimeout(?int $seconds)` like Abacus.
2. **Graceful Fallback:** If the lock cannot be acquired within the timeout (or using `lock('for update nowait')`):
   - Rather than failing the HTTP request, append the signal/intent to the database and dispatch an async background worker.
   - Return a `ProcessHandle` with `status: InstanceStatus::Running`.
   - This ensures HTTP clients never stall or crash during high-concurrency bursts.
