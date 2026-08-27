<?php

namespace Chronicle\Process\Steps;

use Carbon\CarbonInterval;

/**
 * @template T
 */
class AwaitSignalStep extends Step
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public string $label,
        public ?CarbonInterval $timeout = null,
        private mixed $rules = null,
        private string $castType = 'mixed',
        private ?string $class = null,
    ) {}

    public static function await(string $label): self
    {
        return new self($label);
    }

    /**
     * @return self<int>
     */
    public function asInteger(string $rules = 'required|integer'): self
    {
        $this->castType = 'int';
        $this->rules = $rules;
        return $this;
    }

    /**
     * @return self<string>
     */
    public function asString(?string $rules = null): self
    {
        $this->castType = 'string';
        $this->rules = $rules;
        return $this;
    }

    /**
     * @param array<mixed> $rules
     * @return self<array<mixed>>
     */
    public function asArray(array $rules): self
    {
        $this->castType = 'array';
        $this->rules = $rules;
        return $this;
    }

    /**
     * @template TClass
     *
     * @param class-string<TClass> $class
     * @param array<mixed> $rules
     * @return self<TClass>
     */
    public function asClass(string $class, ?array $rules = null): self
    {
        $this->castType = 'class';
        $this->class = $class;
        $this->rules = $rules;
        return $this;
    }

    public function validateAndCast(mixed $payload): mixed
    {
        // TODO validation

        return match ($this->castType) {
            'int' => (int) $payload,
            'string' => (string) $payload,
            'array' => (array) $payload,
            'class' => new $this->class(...$payload),
            default => $payload,
        };
    }
}
