<?php

declare(strict_types=1);

namespace Chronicle\Process\Data;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Chronicle\Process\Contracts\Payload;
use Chronicle\Process\Exceptions\InvalidPayloadException;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon as LaravelCarbon;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Throwable;

final class CommonScalarPayload implements Payload
{
    public const string DATETIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    /** @var array<string, class-string<DateTime|DateTimeImmutable>> */
    private const array DATETIME_TYPES = [
        'datetime' => DateTime::class,
        'datetime_immutable' => DateTimeImmutable::class,
        'carbon' => Carbon::class,
        'carbon_immutable' => CarbonImmutable::class,
        'laravel_carbon' => LaravelCarbon::class,
    ];

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
            $dateType = array_search($value::class, self::DATETIME_TYPES, true);

            if ($dateType === false) {
                throw InvalidPayloadException::unsupportedScalar($value);
            }

            $payload = [
                'k' => 'datetime',
                'date_type' => $dateType,
                'v' => $value->format(self::DATETIME_FORMAT),
                'timezone' => $value->format('e'),
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
            // Older datetime records were always decoded as Carbon.
            $dateType = $this->payload['date_type'] ?? 'carbon';
            $value = $this->payload['v'] ?? null;
            $timezone = $this->payload['timezone'] ?? null;

            if (! is_string($dateType) || ! isset(self::DATETIME_TYPES[$dateType]) || ! is_string($value)
                || ($timezone !== null && ! is_string($timezone))) {
                throw new InvalidPayloadException('Invalid datetime payload.');
            }

            $class = self::DATETIME_TYPES[$dateType];

            try {
                $date = $class::createFromFormat(self::DATETIME_FORMAT, $value);
                $zone = $timezone === null ? null : new DateTimeZone($timezone);
            } catch (Throwable $exception) {
                throw new InvalidPayloadException('Invalid datetime payload.', previous: $exception);
            }

            if (! $date instanceof DateTimeInterface || $date->format(self::DATETIME_FORMAT) !== $value) {
                throw new InvalidPayloadException('Invalid datetime payload.');
            }

            return $zone === null ? $date : $date->setTimezone($zone);
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
        return new self($data);
    }
}
