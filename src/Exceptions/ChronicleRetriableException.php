<?php

declare(strict_types=1);

namespace Chronicle\Process\Exceptions;

use RuntimeException;

/**
 * Exception that chronicle should treat as retriable.
 */
class ChronicleRetriableException extends RuntimeException implements ChronicleException
{
    //
}
