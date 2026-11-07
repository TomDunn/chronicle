<?php

declare(strict_types=1);

namespace Chronicle\Process\Data;

use Carbon\Carbon;
use Chronicle\Process\Contracts\Payload;
use Chronicle\Process\Exceptions\InvalidPayloadException;

final class CommonScalarPayload implements Payload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        private array $payload,
    ) {}

    public static function make(string|Carbon|int $value): static
    {
        $payload = null;

        if ($value instanceof Carbon) {
            $payload = [
                'k' => 'carbon',
                'v' => $value->serialize(),
            ];
        } elseif (is_string($value)) {
            $payload = [
                'k' => 'string',
                'v' => $value,
            ];
        } elseif (is_int($value)) {
            $payload = [
                'k' => 'int',
                'v' => $value,
            ];
        } else {
            throw InvalidPayloadException::unsupportedScalar($value);
        }

        return new CommonScalarPayload($payload);
    }

    public static function type(): string
    {
        return '__chronicle_scalar_payload';
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->payload;
    }

    public function value(): Carbon|int|string
    {
        if ($this->payload['k'] === 'carbon') {
            return Carbon::fromSerialized($this->payload['v']);
        }

        return $this->payload['v'];
    }

    /**
     * Deserialize JSON into a typed payload instance.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromPayload(array $data): static
    {
        return new static($data);
    }
}
