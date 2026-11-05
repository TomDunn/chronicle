<?php

declare(strict_types=1);

namespace Chronicle\Process\Tests\Fixtures;

use Chronicle\Process\Contracts\Process;

final class LaunchableProcess implements Process
{
    public static function getProcessType(): string
    {
        return 'launchable';
    }
}
