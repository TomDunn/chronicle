<?php

declare(strict_types=1);

namespace Chronicle\Process\Definitions;

use Chronicle\Process\Enums\Consistency;

/**
 * @template TRequest of object
 */
final class ProcessDefinition
{
    private Consistency $consistency = Consistency::Eventual;
    /** @var class-string<TRequest> */
    private string $requestClass;

    /**
     * Create a new process definition
     */
    public function __construct(
        private string $name
    ) {}

    public static function name(string $name): static
    {
        return new static($name);
    }

    public function consistency(Consistency $consistency): static
    {
        $this->consistency = $consistency;
        return $this;
    }

    /**
    * @template TNew of object
    *
    * @param class-string<TNew> $requestClass
    * @return self<TNew>
    */
    public function accepting(string $requestClass): static
    {
        $this->requestClass = $requestClass;
        return $this;
    }


}
