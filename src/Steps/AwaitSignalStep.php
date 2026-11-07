<?php

declare(strict_types=1);

namespace Chronicle\Process\Steps;

use Chronicle\Process\Enums\EntryType;

final readonly class AwaitSignalStep extends Step
{
    public function __construct(
        string $stepKey,
        public string $signalName,
        ?string $description = null,
    ) {
        parent::__construct($stepKey, EntryType::SignalWait, $description);
    }
}
