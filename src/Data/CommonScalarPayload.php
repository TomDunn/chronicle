<?php

declare(strict_types=1);

namespace Chronicle\Process\Data;

use Carbon\Carbon;
use Chronicle\Process\Contracts\Payload;
use Chronicle\Process\Exceptions\InvalidPayloadException;
use DateTimeInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class CommonScalarPayload implements Payload
{
    public const string DATETIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        private array $payload,
    ) {}

    public static function make(string|DateTimeInterface|int|float|bool|UuidInterface|null $value): static
    {
        $payload = null;

        if ($value instanceof DateTimeInterface) {
            $payload = [
                'k' => 'datetime',
                'v' => $value->format(self::DATETIME_FORMAT),
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
        } elseif (is_float($value)) {
            $payload = [
                'k' => 'float',
                'v' => $value,
            ];
        } elseif (is_bool($value)) {
            $payload = [
                'k' => 'bool',
                'v' => $value,
            ];
        } elseif (is_null($value)) {
            $payload = [
                'k' => 'null',
                'v' => $value,
            ];
        } elseif ($value instanceof UuidInterface) {
            $payload = [
                'k' => 'uuid',
                'v' => $value->toString(),
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

    public function value(): DateTimeInterface|int|string|bool|float|UuidInterface|null
    {
        if ($this->payload['k'] === 'datetime') {
            return Carbon::createFromFormat(self::DATETIME_FORMAT, $this->payload['v']);
        } elseif ($this->payload['k'] === 'uuid') {
            return Uuid::fromString($this->payload['v']);
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
