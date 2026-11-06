<?php

declare(strict_types=1);

namespace Chronicle\Process;

use Chronicle\Process\Contracts\Payload;
use Chronicle\Process\Contracts\Process;
use Chronicle\Process\Data\ArrayPayload;
use Chronicle\Process\Data\ProcessHandle;
use Chronicle\Process\Models\JournalEntry;
use Chronicle\Process\Models\ProcessInstance;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class Chronicle
{
    /** @var array<string, class-string<Process>> */
    private $processRegistry = [];

    /**
     * @param  class-string<Process>  $process
     */
    public function registerProcess(string $process): self
    {
        if (! is_a($process, Process::class, true)) {
            throw new InvalidArgumentException("$process is not an implementation of Process interface");
        }

        $this->processRegistry[$process::getProcessType()] = $process;

        return $this;
    }

    public function registerPayload(Payload $payload): self
    {
        return $this;
    }

    public function deserializePayload(string $type, array $data): mixed // TODO
    {
        return null;
    }

    /**
     * Launch a new process and execute asynchronously.
     *
     * @param  class-string<Process>  $process
     * @param  array<string, mixed>|Payload  $payload
     */
    public function launch(
        string $process,
        Payload|array $payload,
        ?string $key = null,
    ): ?ProcessHandle {
        if (! in_array($process, $this->processRegistry)) {
            throw new InvalidArgumentException("$process is not registered");
        }

        return DB::connection()->transaction(function () use ($key, $process, $payload) {
            $instance = null;

            if (! $key) {
                $instance = ProcessInstance::query()->create([
                    'process_type' => $process::getProcessType(),
                    'active' => true,
                    'key' => $key,
                    'status' => 'pending',
                    'document' => [],
                ]);
            } else {
                $instance = ProcessInstance::query()->createOrFirst(
                    ['key' => $key, 'process_type' => $process::getProcessType(), 'active' => true],
                    [
                        'status' => 'pending',
                        'document' => [],
                    ],
                );
            }

            if ($instance->wasRecentlyCreated) {
                if (! $payload instanceof Payload) {
                    $payload = ArrayPayload::make($payload);
                }

                JournalEntry::query()->createOrFirst([
                    'process_instance_id' => $instance->id,
                    'entry_type' => 'launch',
                ], [
                    'description' => 'Process launched',
                    'payload_type' => $payload->type(),
                    'payload' => $payload->jsonSerialize(),
                    'step_key' => 'initial_launch',
                    'sequence' => 0,
                ]);
            }

            return new ProcessHandle($instance->id, $instance->process_type, $instance->status, $instance->key, null);
        });
    }

    /**
     * Launch a new process and execute synchronously until blocked.
     *
     * @param  list<mixed>  $params
     */
    public function launchAndProcess(
        string $process,
        array $params,
        ?string $key = null,
    ): ?ProcessHandle {
        return null;
    }

    /**
     * Alias for launchAndProcess
     *
     * @param  list<mixed>  $params
     */
    public function run(
        string $process,
        array $params,
        ?string $key = null,
    ): ?ProcessHandle {
        return null;
    }

    /**
     * Sends a named signal asynchronously to the process.
     */
    public function signal(
        string $signalName,
        ?string $instanceId = null,
        ?string $process = null,
        ?string $key = null,
        ?Payload $payload = null,
    ): ?ProcessHandle {
        return null;
    }

    /**
     * Sends a named signal and resumes processing synchronously until blocking occurs or the process completes.
     */
    public function signalAndProcess(
        string $signalName,
        ?string $instanceId = null,
        ?string $process = null,
        ?string $key = null,
        ?Payload $payload = null,
    ): ?ProcessHandle {
        return null;
    }

    // findInstanceById - returns a process instance if it exists, does not acquire locks
    // accepts an optional process type string to narrow results to the specificied type

    // findInstanceByKey - returns an active process instance if it exists with the
    // matching key. Null otherwise. An optional process type string is accepted.

    // queryProcessInstancesByKey - returns all process instances with the key, active or not.

    // message (?string $processInstanceId = null, ?string $key = null, ?string $processType = null, MessagePayload $payload)
    // send a message to a process
}
