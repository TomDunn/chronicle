<?php

namespace Chronicle\Process\Steps;

abstract readonly class Step
{
    public function __construct(public string $label) {}
}
