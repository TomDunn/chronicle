<?php

declare(strict_types=1);

namespace Chronicle\Process\Contracts;

/**
 * @template TLaunchPayload of Payload
 */
interface Process
{
    public static function getProcessType(): string;

    /**
     * @param  TLaunchPayload  $launchPayload
     */
    public function run(Payload $launchPayload): mixed;
}
