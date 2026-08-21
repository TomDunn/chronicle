<?php

declare(strict_types=1);

namespace Chronicle\Process\Tests\Fixtures;

use Chronicle\Process\Process;
use Generator;

/**
 * @extends Process<array{reference: string, charge: string}>
 */
final class DummyProcess extends Process
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
        $reference = yield from $this->sideEffect(
            'reference',
            fn (): string => 'ref-'.random_int(1000, 9999),
        );

        $charge = yield from $this->sideEffect(
            'charge',
            fn (): string => 'ch-'.strtoupper($reference),
        );

        return ['reference' => $reference, 'charge' => $charge];
    }
}
