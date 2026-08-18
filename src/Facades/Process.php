<?php

declare(strict_types=1);

namespace Chronicle\Process\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Chronicle\Process\Process
 */
class Process extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Chronicle\Process\Process::class;
    }
}
