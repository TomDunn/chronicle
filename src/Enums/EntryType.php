<?php

declare(strict_types=1);

namespace Chronicle\Process\Enums;

enum EntryType: string
{
    case Launch = 'launch';
    case Activity = 'activity';
    case Wait = 'wait';
    case SignalWait = 'signal_wait';
    case Resume = 'resume';
    case Signal = 'signal';
    case ActivityError = 'activity_error';
    case ActivityFailed = 'activity_failure';
    case ProcessFailure = 'process_failure';
}
