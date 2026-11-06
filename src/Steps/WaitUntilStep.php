<?php

declare(strict_types=1);

namespace Chronicle\Process\Steps;

use DateTimeImmutable;

final readonly class WaitUntilStep extends Step
{
    public function __construct(
        string $stepKey,
        public DateTimeImmutable $until,
        ?string $description = null,
    ) {
        parent::__construct($stepKey, $description);
    }
}
