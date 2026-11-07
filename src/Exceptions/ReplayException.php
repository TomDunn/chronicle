<?php

declare(strict_types=1);

namespace Chronicle\Process\Exceptions;

use RuntimeException;

final class ReplayException extends RuntimeException implements ChronicleException
{
    public static function stepMismatch(string|int $instanceId, string $expectedKey, string $actualKey): self
    {
        return new self("Replay mismatch for process instance [{$instanceId}]. Expected step [{$expectedKey}], but process produced [{$actualKey}].");
    }

    public static function stepEntryTypeMismatch(string|int $instanceId, string $expectedType, string $actualType): self
    {
        return new self("Replay mismatch for process instance [{$instanceId}]. Expected step type [{$expectedType}], but process produced [{$actualType}].");
    }

    public static function unexpectedEarlyReturn(string|int $instanceId): self
    {
        return new self("Replay mismatch for process instance [{$instanceId}]. Process returned before consuming all recorded journal entries.");
    }
}
