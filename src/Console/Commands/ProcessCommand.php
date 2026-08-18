<?php

declare(strict_types=1);

namespace Chronicle\Process\Console\Commands;

use Illuminate\Console\Command;

class ProcessCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'chronicle:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package chronicle.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('Process placeholder command executed.');

        return self::SUCCESS;
    }
}
