<?php

declare(strict_types=1);

namespace Chronicle\Process\Exceptions;

final class ProcessNotCompletedException extends ChronicleNonRetriableException
{
    public static function forInstance(string|int $instanceId, string $status): self
    {
        return new self("Cannot retrieve return value: process instance [{$instanceId}] has not completed (current status: {$status}).");
    }
}
