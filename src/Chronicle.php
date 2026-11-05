<?php

declare(strict_types=1);

namespace Chronicle\Process;

use Chronicle\Process\Contracts\Process;
use ProcessHandle;

final class Chronicle
{
    public function registerProcess(Process $process): self
    {
        return $this;
    }

    public function registerPayload(string $type, string $class): self
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

    public function launch(

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
