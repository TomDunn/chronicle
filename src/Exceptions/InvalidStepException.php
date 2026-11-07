<?php

declare(strict_types=1);

namespace Chronicle\Process\Exceptions;

use RuntimeException;

final class InvalidStepException extends RuntimeException implements ChronicleException
{
    public static function notAStep(string|int $instanceId): self
    {
        return new self("Process instance [{$instanceId}] yielded something other than a step!");
    }
}
