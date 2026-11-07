<?php

declare(strict_types=1);

namespace Chronicle\Process\Steps;

use Chronicle\Process\Enums\EntryType;
use Closure;

final readonly class ActivityStep extends Step
{
    /**
     * @param  Closure(): mixed  $callback
     */
    public function __construct(
        string $stepKey,
        public Closure $callback,
        ?string $description = null,
    ) {
        parent::__construct($stepKey, EntryType::Activity, $description);
    }
}
