<?php

namespace Chronicle\Process\Enums;

enum InstanceStatus: string
{
    case AwaitingTimer = 'wait-timer';
    case AwaitingSignal = 'wait-signal';
}
