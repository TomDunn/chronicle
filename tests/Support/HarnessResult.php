<?php

declare(strict_types=1);

namespace Chronicle\Process\Tests\Support;

use Chronicle\Process\Steps\Step;

final readonly class HarnessResult
{
    /**
     * @param  list<Step>  $steps
     * @param  array<string, mixed>  $history
     * @param  list<string>  $executed
     */
    public function __construct(
        public mixed $value,
        public array $steps,
        public array $history,
        public array $executed,
    ) {}
}
