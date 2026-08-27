<?php

declare(strict_types=1);

namespace Chronicle\Process;

use Carbon\CarbonInterval;
use Chronicle\Process\Enums\Consistency;
use Chronicle\Process\Steps\AwaitSignalStep;
use Chronicle\Process\Steps\SideEffectStep;
use Chronicle\Process\Steps\Step;
use Chronicle\Process\Steps\TimerStep;
use Closure;
use Generator;

/**
* @template TResult = null
*/
abstract class Process
{
    abstract public function handle(): Generator;

    abstract public function name(): string;

    abstract public function key(): string;

    public function consistency(): Consistency
    {
        return Consistency::Eventual;
    }

    public function guard(): bool|string
    {
        return true;
    }

    /**
    * @template TEffectResult of scalar|array<mixed>|null
    *
    * @param Closure(): TEffectResult $effect
    * @return Generator<int, Step, mixed, TEffectResult>
    */
    protected function sideEffect(string $label, Closure $effect): Generator
    {
        $result = yield (new SideEffectStep($label, $effect));
        return $result;
    }

    protected function timer(string $label, CarbonInterval $duration): TimerStep
    {
        return new TimerStep($label, $duration);
    }

    /**
    * @template TSignalPayload
    * @param AwaitSignalStep<TSignalPayload> $step
    * @return Generator<int, Step, mixed, TSignalPayload>
    */
    protected function awaitSignal(AwaitSignalStep $step): Generator
    {
        $payload = yield $step;
        return $payload;
    }

    /**
    * @return AwaitSignalStep<mixed>
    */
    protected function defineSignal(string $label, ?CarbonInterval $duration = null): AwaitSignalStep
    {
        return new AwaitSignalStep(label: $label, timeout: $duration);
    }
}
