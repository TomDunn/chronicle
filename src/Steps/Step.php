<?php

declare(strict_types=1);

namespace Chronicle\Process\Steps;

use Chronicle\Process\Enums\EntryType;

abstract readonly class Step
{
    public function __construct(
        public string $stepKey,
        public EntryType $entryType,
        public ?string $description = null,
    ) {}
}
