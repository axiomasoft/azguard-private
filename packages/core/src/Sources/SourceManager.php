<?php

declare(strict_types=1);

namespace AzGuard\Sources;

use AzGuard\Attributes\AsSource;
use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\UnknownSourceException;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Manager;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Names of sources and the factories that build a new instance for every panel.
 *
 * The manager does not keep a driver cache: `make()` calls the creator every time, so two panels never share an
 * object. There is no default source.
 *
 * @api
 */
final class SourceManager extends Manager
{
    /** @var array<string, class-string<Source>> source name => class declared with `#[AsSource]` */
    private array $attributes = [];

    public function getDefaultDriver(): string
    {
        throw new UnknownSourceException('There is no default source: name the source in permissions([...]).');
    }

    /**
     * @param  Closure(Application, array<string, mixed>): Source  $callback
     */
    public function extend($driver, Closure $callback)
    {
        if ($driver === '') {
            throw new DefinitionException('extend() expects a source name.');
        }

        $this->guard($driver, 'extend()');
        parent::extend($driver, $callback);

        return $this;
    }

    /**
     * Registers a class that declares `#[AsSource]`. Registering the same class again is a no-op.
     *
     * @param  class-string<Source>  $class
     *
     * @throws DefinitionException when the class has no attribute, or its name is already taken
     */
    public function register(string $class): void
    {
        $name = $this->attributeName($class);

        if (($this->attributes[$name] ?? null) === $class) {
            return;
        }

        $this->guard($name, $class);
        $this->attributes[$name] = $class;
    }

    /**
     * A new instance of the named source. The creator receives the parameters of `azguard.sources.<name>`.
     *
     * @throws UnknownSourceException when nothing is registered under the name
     * @throws DefinitionException when the creator returns something that is not a source
     */
    public function make(string $name, string $panel): Source
    {
        $config = $this->container->make(AzGuardConfig::class)->source($name);

        $created = match (true) {
            isset($this->customCreators[$name]) => ($this->customCreators[$name])($this->application(), $config),
            isset($this->attributes[$name]) => $this->container->makeWith($this->attributes[$name], ['config' => $config]),
            default => throw new UnknownSourceException(
                'No source is registered under the name "'.$name.'": add #[AsSource(\''.$name.'\')] or sources()->extend().',
            ),
        };

        if (! $created instanceof Source) {
            throw new DefinitionException(
                'The creator of source "'.$name.'" for panel "'.$panel.'" returned '.get_debug_type($created).', which is not a source.',
            );
        }

        return $created;
    }

    /**
     * @param  class-string  $class
     */
    private function attributeName(string $class): string
    {
        if (! class_exists($class) || ! is_a($class, Source::class, true)) {
            throw new DefinitionException($class.' is not a source class: it must implement '.Source::class.'.');
        }

        $attributes = (new ReflectionClass($class))->getAttributes(AsSource::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attributes === []) {
            throw new DefinitionException($class.' has no #[AsSource] attribute: the name of the source is declared on the class.');
        }

        if (count($attributes) > 1) {
            throw new DefinitionException($class.' declares #[AsSource] more than once: a class has one source name.');
        }

        $name = $attributes[0]->newInstance()->name;

        if ($name === '') {
            throw new DefinitionException($class.' declares an empty #[AsSource] name.');
        }

        return $name;
    }

    private function guard(string $name, string $owner): void
    {
        $taken = isset($this->customCreators[$name]) || isset($this->attributes[$name]);

        if ($taken) {
            throw new DefinitionException(
                'Source "'.$name.'" is already registered; '.$owner.' cannot register it again. Registering the same class twice is ignored.',
            );
        }
    }

    private function application(): Application
    {
        $container = $this->container;

        if (! $container instanceof Application) {
            throw new DefinitionException('SourceManager needs the application container.');
        }

        return $container;
    }
}
