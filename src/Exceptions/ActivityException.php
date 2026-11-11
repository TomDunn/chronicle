<?php

declare(strict_types=1);

namespace Chronicle\Process\Exceptions;

use Chronicle\Process\Enums\EntryType;
use RuntimeException;
use Throwable;

final class ActivityException extends RuntimeException implements ChronicleException
{
    public static function activityError(string|int $instanceId, string $entryKey, Throwable $e): self
    {

        return new self("Exception caught while running activity  {$entryKey} in instance {$instanceId}", $e);
    }
}
