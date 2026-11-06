<?php

declare(strict_types=1);

namespace Chronicle\Process;

use Chronicle\Process\Steps\ActivityStep;
use Chronicle\Process\Steps\AwaitSignalStep;
use Chronicle\Process\Steps\WaitUntilStep;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;

final class Steps
{
    /**
     * @param  Closure(): mixed  $callback
     */
    public static function activity(
        string $stepKey,
        Closure $callback,
        ?string $description = null,
    ): ActivityStep {
        return new ActivityStep($stepKey, $callback, $description);
    }

    public static function now(
        string $stepKey,
        ?string $description = null,
    ): ActivityStep {
        return new ActivityStep($stepKey, fn () => now(), $description ?? 'Get timestamp');
    }

    public static function uuid(
        string $stepKey,
        ?string $description = null,
    ): ActivityStep {
        return new ActivityStep($stepKey, fn () => Str::uuid(), $description ?? 'Get a uuid');
    }

    public static function waitUntil(
        string $stepKey,
        DateTimeInterface $until,
        ?string $description = null,
    ): WaitUntilStep {
        return new WaitUntilStep(
            $stepKey,
            DateTimeImmutable::createFromInterface($until),
            $description,
        );
    }

    public static function awaitSignal(
        string $stepKey,
        string $signalName,
        ?string $description = null,
    ): AwaitSignalStep {
        return new AwaitSignalStep($stepKey, $signalName, $description);
    }
}
