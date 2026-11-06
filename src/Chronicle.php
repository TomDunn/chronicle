<?php

declare(strict_types=1);

namespace Chronicle\Process;

use Chronicle\Process\Contracts\Payload;
use Chronicle\Process\Contracts\Process;
use Chronicle\Process\Data\ProcessHandle;
use Chronicle\Process\Models\ProcessInstance;
use InvalidArgumentException;

final class Chronicle
{
    /** @var array<string, class-string<Process>> $processRegistry */
    private $processRegistry = [];

    /**
     * @param class-string<Process> $process
     */
    public function registerProcess(string $process): self
    {
        if (!is_a($process, Process::class, true)) {
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

    // the ability to start a process
    //
    // Accepts a registered process type string
    // Accepts constructor params for the process
    // Accepts optional "key" or "token" which is used
    // by the Chronicle engine to ensure that there is at
    // most one active process instance per key/token.
    //
    // Ability to specify the process should run async
    // or start processing synchronously.
    //
    // if a key is provided we check if any process instance is active with that key, if so,
    // return that process instance.
    //
    // if not, use a process_key table, perform an insert-or-ignore of the key, then acquire
    // a write lock and attempt to create an active process instance for that key if not exists.
    //
    // a process instance consists of:
    //   id - ULID most likely
    //   type - a registered process type string
    //   key - nullable string
    //   active - true/false. false meaning the process instance can no longer run - it has completed or otherwise terminated. true simply means
    //   the process has not terminated yet. true does not necessarily mean the process instance is actively executing.
    //   created_at, updated_at
    //

    /**
     * Launch a new process and execute asynchronously.
     *
     * @param  class-string<Process>  $process
     * @param  list<mixed>  $params
     */
    public function launch(
        string $process,
        array $params,
        ?string $key = null,
    ): ?ProcessHandle {

        if (! in_array($process, $this->processRegistry)) {
            throw new InvalidArgumentException("$process is not registered");
        }

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
                ]
            );
        }


        // try to insert a process instance
        //  with key, status = pending, etc....
        //
        //  if insert fails due to key constraint, return handle for existing process
        //  otherwise return handle for new process
        return new ProcessHandle($instance->id, $instance->process_type, $instance->status, $instance->key, null);
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
