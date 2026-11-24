<?php

declare(strict_types=1);

namespace Chronicle\Process\Steps;

use Chronicle\Process\Enums\EntryType;
use Closure;

final readonly class ActivityStep extends Step
{
    /**
     * @param  Closure(): mixed  $callback
     * @param array<int> $backOffs
     * @param Closure(Throwable): bool $when
     */
    public function __construct(
        string $stepKey,
        public Closure $callback,
        ?string $description = null,
        public int $maxAttempts = 1,
        public ?array $backOffs = null,
        public ?Closure $when = null,
    ) {
        parent::__construct($stepKey, EntryType::Activity, $description);
    }


    /**
     * @param array<int> $backOffs
     * @param Closure(Throwable): bool $when
     */
    public function retry(
        int $maxAttempts,
        array $backOffs,
        Closure $when,
    ): static {
        $this->maxAttempts = $maxAttempts;
        $this->backOffs = $backOffs;
        $this->when = $when;
        return $this;
    }
}
