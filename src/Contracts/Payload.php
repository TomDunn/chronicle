<?php

declare(strict_types=1);

namespace Chronicle\Process\Contracts;

use JsonSerializable;

interface Payload extends JsonSerializable
{
    public function type(): string;

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array;

    /**
     * Deserialize JSON into a typed payload instance.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromPayload(array $data): static;
}
