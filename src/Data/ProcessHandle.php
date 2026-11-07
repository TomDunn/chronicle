<?php

declare(strict_types=1);

namespace Chronicle\Process\Data;

use Chronicle\Process\Exceptions\ProcessNotCompletedException;

final class ProcessHandle
{
    public function __construct(
        public string $id,
        public string $type,
        public string $status,
        public ?string $key = null,
        public mixed $result = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function key(): ?string
    {
        return $this->key;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function hasCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Final workflow return value.
     * Throws ProcessNotCompletedException if the process has not completed.
     */
    public function value(): mixed
    {
        if (! $this->hasCompleted()) {
            throw ProcessNotCompletedException::forInstance($this->id, $this->status);
        }

        return $this->result;
    }

    /**
     * Return value or default fallback if suspended/running.
     */
    public function valueOr(mixed $default = null): mixed
    {
        return $this->result ?? $default;
    }

    /**
     * If suspended, returns the Hangup metadata (eligible_at, waiting signal label, etc.)
     */
    // public function hangup(): ?Hangup;

    /**
     * Convenience methods for checking wait conditions:
     */
    // public function isAwaitingSignal(?string $signal = null): bool;
    // public function isAwaitingTimer(): bool;
}
