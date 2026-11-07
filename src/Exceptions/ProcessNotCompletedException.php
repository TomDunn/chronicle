<?php

declare(strict_types=1);

namespace Chronicle\Process\Exceptions;

use RuntimeException;

final class ProcessNotCompletedException extends RuntimeException implements ChronicleException
{
    public static function forInstance(string|int $instanceId, string $status): self
    {
        return new self("Cannot retrieve return value: process instance [{$instanceId}] has not completed (current status: {$status}).");
    }
}
