<?php

declare(strict_types=1);

namespace Chronicle\Process\Tests\Fixtures;

use Chronicle\Process\Contracts\Payload;
use Chronicle\Process\Contracts\Process;
use Chronicle\Process\Data\ArrayPayload;
use Chronicle\Process\Steps;
use Generator;

/**
 * @implements Process<ArrayPayload>
 */
final class LaunchableProcess implements Process
{
    public static function getProcessType(): string
    {
        return 'launchable';
    }

    /**
     * @param  ArrayPayload  $launchPayload
     */
    public function run(Payload $launchPayload, Steps $steps): Generator
    {
        return null;
    }
}
