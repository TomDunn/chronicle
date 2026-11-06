<?php

declare(strict_types=1);

namespace Chronicle\Process\Facades;

use Chronicle\Process\Chronicle as ChronicleService;
use Chronicle\Process\Contracts\Payload;
use Chronicle\Process\Data\ProcessHandle;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ChronicleService registerProcess(string $process)
 * @method static ChronicleService registerPayload(Payload $payload)
 * @method static mixed deserializePayload(string $type, array<string, mixed> $data)
 * @method static ProcessHandle|null launch(string $process, Payload|array<string, mixed> $payload, string|null $key = null)
 * @method static ProcessHandle|null launchAndProcess(string $process, list<mixed> $params, string|null $key = null)
 * @method static ProcessHandle|null run(string $process, list<mixed> $params, string|null $key = null)
 * @method static ProcessHandle|null signal(string $signalName, string|null $instanceId = null, string|null $process = null, string|null $key = null, Payload|null $payload = null)
 * @method static ProcessHandle|null signalAndProcess(string $signalName, string|null $instanceId = null, string|null $process = null, string|null $key = null, Payload|null $payload = null)
 *
 * @see ChronicleService
 */
class Chronicle extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ChronicleService::class;
    }
}
