<?php

declare(strict_types=1);

namespace Chronicle\Process\Enums;

enum EntryType: string
{
    case Launch = 'launch';
    case Activity = 'activity';
    case Wait = 'wait';
    case SignalWait = 'signal_wait';
    case Signal = 'signal';
    case ActivityError = 'activity_error';
    case ProcessError = 'process_error';
}
