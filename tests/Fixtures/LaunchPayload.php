<?php

declare(strict_types=1);

namespace Chronicle\Process\Tests\Fixtures;

use Chronicle\Process\Contracts\Payload;

final class LaunchPayload implements Payload
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data) {}

    public static function type(): string
    {
        return 'test-launch';
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromPayload(array $data): static
    {
        return new self($data);
    }
}
