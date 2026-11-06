<?php

declare(strict_types=1);

namespace Chronicle\Process\Enums;

enum ExecutionOutcome: string
{
    case Completed = 'completed';
    case Suspended = 'suspended';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
