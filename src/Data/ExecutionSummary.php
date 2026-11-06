<?php

declare(strict_types=1);

namespace Chronicle\Process\Data;

use Chronicle\Process\Enums\ExecutionOutcome;
use Throwable;

final readonly class ExecutionSummary
{
    public function __construct(
        public string $instanceId,
        public ExecutionOutcome $outcome,
        public mixed $result = null,
        public ?Throwable $error = null,
    ) {}
}
