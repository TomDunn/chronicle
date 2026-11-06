<?php

declare(strict_types=1);

namespace Chronicle\Process\Steps;

abstract readonly class Step
{
    public function __construct(
        public string $stepKey,
        public ?string $description = null,
    ) {}
}
