<?php

declare(strict_types=1);

namespace Chronicle\Process\Tests\Support;

use Chronicle\Process\Process;
use Chronicle\Process\Steps\SideEffectStep;
use Chronicle\Process\Steps\Step;
use LogicException;

/**
 * Drives a process generator to completion in memory.
 *
 * Stands in for the real executor so process behaviour can be asserted
 * before the executor exists.
 */
final class ProcessHarness
{
    /** @var list<Step> */
    private array $steps = [];

    /** @var array<string, mixed> */
    private array $history = [];

    /** @var array<string, mixed> */
    private array $recorded = [];

    /** @var list<string> */
    private array $executed = [];

    /** @param Process<mixed> $process */
    private function __construct(
        private readonly Process $process,
    ) {}

    /** @param Process<mixed> $process */
    public static function for(Process $process): self
    {
        return new self($process);
    }

    /**
     * Pre-recorded step results, as written by an earlier run.
     *
     * @param  array<string, mixed>  $history
     */
    public function withHistory(array $history): self
    {
        $this->history = $history;

        return $this;
    }

    public function run(): HarnessResult
    {
        $generator = $this->process->handle();

        while ($generator->valid()) {
            $step = $generator->current();

            $this->steps[] = $step;

            $generator->send($this->resolve($step));
        }

        return new HarnessResult(
            $generator->getReturn(),
            $this->steps,
            $this->recorded,
            $this->executed,
        );
    }

    private function resolve(Step $step): mixed
    {
        return match (true) {
            $step instanceof SideEffectStep => $this->resolveSideEffect($step),
            default => throw new LogicException(
                sprintf('The harness cannot resolve [%s] yet.', $step::class),
            ),
        };
    }

    private function resolveSideEffect(SideEffectStep $step): mixed
    {
        if (array_key_exists($step->label, $this->history)) {
            return $this->recorded[$step->label] = $this->history[$step->label];
        }

        $this->executed[] = $step->label;

        return $this->recorded[$step->label] = ($step->effect)();
    }
}
