<?php

declare(strict_types=1);

namespace Chronicle\Process\Exceptions;

use RuntimeException;

final class InvalidPayloadException extends RuntimeException implements ChronicleException
{
    public static function unsupportedScalar(mixed $value): self
    {
        $type = gettype($value);
        return new self("Unsupported scalar of type {$type}");
    }

    public static function unregisteredType(string $type): self
    {
        return new self("Unregistered type {$type}");
    }
}
