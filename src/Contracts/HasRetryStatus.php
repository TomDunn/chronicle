<?php

declare(strict_types=1);

namespace Chronicle\Process\Contracts;

interface HasRetryStatus
{
    public function isRetryable(): bool;
}
