<?php

declare(strict_types=1);

namespace Chronicle\Process\Data;

use Chronicle\Process\Contracts\Payload;

final class ArrayPayload implements Payload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function make(array $payload): static
    {
        return new ArrayPayload($payload);
    }

    public function type(): string
    {
        return '__chronicle_array_payload';
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->payload;
    }

    /**
     * Deserialize JSON into a typed payload instance.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromPayload(array $data): static
    {
        return self::make($data);
    }
}
