<?php

declare(strict_types=1);

namespace Chronicle\Process\Data;

use Chronicle\Process\Contracts\Payload;
use Throwable;

final readonly class ExceptionPayload implements Payload
{
    public const string TYPE = '__chronicle_exception_payload';

    public function __construct(
        public string $exceptionType,
        public string $exceptionMessage,
    ) {}

    public static function make(Throwable $e): static
    {
        return new self($e::class, $e->getMessage());
    }

    public static function type(): string
    {
        return self::TYPE;
    }

    /**
     * @return array{exceptionType: string, exceptionMessage: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'exceptionType' => $this->exceptionType,
            'exceptionMessage' => $this->exceptionMessage,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromPayload(array $data): static
    {
        return new self($data['exceptionType'], $data['exceptionMessage']);
    }
}
