<?php

namespace Chronicle\Process\Steps;

use Closure;

final readonly class SideEffectStep extends Step
{
    /**
     * @param Closure(): mixed $effect
     */
    public function __construct(public string $label, public Closure $effect) {}
}
