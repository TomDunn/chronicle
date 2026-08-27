<?php

namespace Chronicle\Process\Steps;

abstract class Step
{
    public function __construct(public string $label) {}
}
