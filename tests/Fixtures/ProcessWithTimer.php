<?php

declare(strict_types=1);

namespace Chronicle\Process\Tests\Fixtures;

use Carbon\CarbonInterval;
use Chronicle\Process\Process;
use Generator;

/**
 * @extends Process<array{email: string}>
 */
final class ProcessWithTimer extends Process
{
    public function __construct(
        private readonly string $email = 'user@example.com',
    ) {}

    public function name(): string
    {
        return 'dummy';
    }

    public function key(): string
    {
        return $this->email;
    }

    public function handle(): Generator
    {
        yield $this->timer('wait', CarbonInterval::fromString('1 day'));

        return ['email' => $this->email];
    }
}
