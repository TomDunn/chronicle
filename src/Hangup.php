<?php

declare(strict_types=1);

namespace Chronicle\Process;

use Carbon\CarbonImmutable;
use Chronicle\Process\Enums\InstanceStatus;

final class Hangup
{
    public function __construct(
        public CarbonImmutable $waitingUntil,
        public InstanceStatus $status,
    ) {}
}
