<?php

declare(strict_types=1);

namespace Chronicle\Process\Tests\Fixtures;

use Chronicle\Process\Contracts\Payload;
use Chronicle\Process\Contracts\Process;
use Closure;

/**
 * @implements Process<Payload>
 */
final class ExecutorProcess implements Process
{
    /**
     * @param  Closure(Payload): mixed  $handler
     */
    public function __construct(private Closure $handler) {}

    public static function getProcessType(): string
    {
        return 'executor-test';
    }

    public function run(Payload $launchPayload): mixed
    {
        return ($this->handler)($launchPayload);
    }
}
