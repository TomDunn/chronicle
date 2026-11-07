<?php

declare(strict_types=1);

namespace Chronicle\Process\Data;

use Chronicle\Process\Contracts\Payload;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class WaitPayload implements Payload
{
    public const string TYPE = '__chronicle_wait_payload';

    public const string FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        public DateTimeImmutable $until,
    ) {}

    public static function make(DateTimeInterface $until): static
    {
        return new self(DateTimeImmutable::createFromInterface($until));
    }

    public static function type(): string
    {
        return self::TYPE;
    }

    /**
     * @return array{until: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'until' => $this->until->format(self::FORMAT),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromPayload(array $data): static
    {
        if (! isset($data['until']) || ! is_string($data['until'])) {
            throw new InvalidArgumentException("Missing or invalid 'until' key in wait payload.");
        }

        $until = DateTimeImmutable::createFromFormat(self::FORMAT, $data['until'])
            ?: new DateTimeImmutable($data['until']);

        return new self($until);
    }

    /**
     * The unboxed payload value (matching ArrayPayload and CommonScalarPayload).
     */
    public function value(): DateTimeImmutable
    {
        return $this->until;
    }

    /**
     * Check if the deadline has been reached (used by Executor for hangup vs resume).
     */
    public function hasPassed(?DateTimeInterface $now = null): bool
    {
        $current = $now !== null
            ? DateTimeImmutable::createFromInterface($now)
            : new DateTimeImmutable();

        return $current >= $this->until;
    }
}
