<?php

declare(strict_types=1);

namespace Chronicle\Process\Exceptions;

trait NonRetryable
{
    public function isRetryable(): bool
    {
        return false;
    }
}
