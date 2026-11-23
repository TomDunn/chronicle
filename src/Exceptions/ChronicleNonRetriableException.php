<?php

declare(strict_types=1);

namespace Chronicle\Process\Exceptions;

use RuntimeException;

/**
 * Exception that chronicle should treat as fatal (no retries).
 */
class ChronicleNonRetriableException extends RuntimeException implements ChronicleException
{
    //
}
