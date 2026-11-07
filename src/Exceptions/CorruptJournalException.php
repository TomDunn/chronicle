<?php

declare(strict_types=1);

namespace Chronicle\Process\Exceptions;

use Chronicle\Process\Enums\EntryType;
use RuntimeException;

final class CorruptJournalException extends RuntimeException implements ChronicleException
{
    public static function missingLaunchEntry(string|int $instanceId, string|EntryType|null $actual = null): self
    {
        $found = match (true) {
            $actual instanceof EntryType => $actual->value,
            is_string($actual) => $actual,
            default => 'none (empty journal)',
        };

        return new self("Process instance [{$instanceId}] journal is corrupt. Expected first entry to be [launch], but found [{$found}].");
    }
}
