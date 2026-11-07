<?php

declare(strict_types=1);

namespace Chronicle\Process\Contracts;

use Chronicle\Process\Steps;

/**
 * @template TLaunchPayload of Payload
 */
interface Process
{
    public static function getProcessType(): string;

    /**
     * @param  TLaunchPayload  $launchPayload
     */
    public function run(Payload $launchPayload, Steps $steps): mixed;
}
