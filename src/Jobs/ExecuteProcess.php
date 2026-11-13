<?php

namespace Chronicle\Process\Jobs;

use Chronicle\Process\Executor;
use Chronicle\Process\Models\ProcessInstance;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteProcess implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ProcessInstance $instance
    ) {
        //
    }

    public function handle(Executor $executor): void
    {
        $executor->execute($this->instance->toHandle());
    }
}
