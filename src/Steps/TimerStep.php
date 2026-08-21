<?php

namespace Chronicle\Process\Steps;

use Carbon\CarbonInterval;

final readonly class TimerStep extends Step
{
    public function __construct(public string $label, public CarbonInterval $interval) {}
}
