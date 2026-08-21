<?php

namespace Chronicle\Process\Enums;

enum Consistency: string
{
    case Atomic = 'atomic';
    case Eventual = 'eventual';
}
