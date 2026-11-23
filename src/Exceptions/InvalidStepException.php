<?php

declare(strict_types=1);

namespace Chronicle\Process\Exceptions;

final class InvalidStepException extends ChronicleNonRetriableException
{
    public static function notAStep(string|int $instanceId): self
    {
        return new self("Process instance [{$instanceId}] yielded something other than a step!");
    }

    public static function unknownStep(string|int $instanceId, mixed $step): self
    {
        $step = $step::class;

        return new self("Process instance [{$instanceId}] yielded something other than a step! {$step}");
    }
}
