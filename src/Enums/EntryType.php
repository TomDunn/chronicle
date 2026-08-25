<?php

namespace Chronicle\Process\Enums;

enum EntryType: string
{
    case Step = 'step';
    case SignalReceived = 'signal-received';
    case SignalConsumed = 'signal-consumed';
}
