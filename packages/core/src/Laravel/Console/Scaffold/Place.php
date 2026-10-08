<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Scaffold;

/**
 * @internal A directory of the generated structure together with the namespace of its classes.
 */
final readonly class Place
{
    public function __construct(public string $directory, public string $namespace) {}

    /** The subdirectory under a relative path such as `Permissions/Sales/Orders`. */
    public function in(string $relative): self
    {
        $relative = trim($relative, '/');

        if ($relative === '') {
            return $this;
        }

        return new self($this->directory.'/'.$relative, $this->namespace.'\\'.str_replace('/', '\\', $relative));
    }

    public function file(string $class): string
    {
        return $this->directory.'/'.$class.'.php';
    }

    /** @return class-string */
    public function fqcn(string $class): string
    {
        /** @var class-string $name */
        $name = $this->namespace.'\\'.$class;

        return $name;
    }
}
