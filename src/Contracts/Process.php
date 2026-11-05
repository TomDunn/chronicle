<?php

declare(strict_types=1);

namespace Chronicle\Process\Contracts;

interface Process
{
    public static function getProcessType(): string;
}
