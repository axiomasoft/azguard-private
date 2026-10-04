<?php

declare(strict_types=1);

namespace AzGuard\Policies;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;

/** Binds operation inputs by name before service DI; never asks DI to invent a reserved model. */
final readonly class RuntimeInvoker
{
    public function __construct(private Container $container) {}

    /** @param array<string,mixed> $inputs */
    public function invoke(callable $callback, array $inputs): mixed
    {
        $closure = Closure::fromCallable($callback);
        $named = [];
        $used = [];
        foreach ((new ReflectionFunction($closure))->getParameters() as $position => $parameter) {
            $name = $parameter->getName();
            $slot = array_key_exists($name, $inputs) ? $name : null;
            $before = array_key_exists('ability', $inputs) && array_key_exists('user', $inputs);
            $resourcePosition = $before ? 2 : 1;

            if (array_key_exists('user', $inputs) && ($slot === null || in_array($slot, ['user', 'resource'], true))) {
                if ($position === 0) {
                    $slot = 'user';
                } elseif ($before && $position === 1) {
                    $slot = 'ability';
                } elseif ($position === $resourcePosition && array_key_exists('resource', $inputs)
                    && ($slot !== null || $this->accepts($parameter->getType(), $inputs['resource']) || $this->modelType($parameter->getType())
                        || ($this->stringType($parameter->getType()) && isset($inputs['resourceClass'])))) {
                    $slot = 'resource';
                }
            } elseif ($slot === null) {
                $candidates = [];
                foreach ($inputs as $key => $value) {
                    if (! isset($used[$key]) && $value !== null && $parameter->getType() !== null && $this->accepts($parameter->getType(), $value)) {
                        $candidates[] = $key;
                    }
                }

                if (count($candidates) > 1) {
                    throw new RuntimeException('Ambiguous runtime input for '.$name.'.');
                }
                $slot = $candidates[0] ?? null;

                if ($slot === null && $parameter->getType() === null) {
                    $slot = array_keys($inputs)[$position] ?? null;
                }
            }

            if ($slot === null) {
                if ($this->modelType($parameter->getType())) {
                    throw new RuntimeException('No runtime model supplied for '.$name.'.');
                }

                continue;
            }
            $value = $inputs[$slot];

            if ($slot === 'resource' && $value === null && isset($inputs['resourceClass']) && $this->stringType($parameter->getType())) {
                $value = $inputs['resourceClass'];
            }

            if (! $this->accepts($parameter->getType(), $value)) {
                if ($value === null && $parameter->isDefaultValueAvailable()) {
                    $value = $parameter->getDefaultValue();
                } else {
                    throw new RuntimeException('Missing or incompatible runtime input '.$slot.' for '.$name.'.');
                }
            }

            $named[$name] = $value;
            $used[$slot] = true;
        }

        return $this->container->call($closure, $named);
    }

    private function accepts(?ReflectionType $type, mixed $value): bool
    {
        if ($type === null) {
            return true;
        }

        if ($value === null) {
            return $type->allowsNull();
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $part) {
                if ($this->accepts($part, $value)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $part) {
                if (! $this->accepts($part, $value)) {
                    return false;
                }
            }

            return true;
        }

        if (! $type instanceof ReflectionNamedType) {
            return false;
        }
        $name = $type->getName();

        if (! $type->isBuiltin()) {
            return $value instanceof $name;
        }

        return match ($name) {
            'mixed' => true,'object' => is_object($value),'string' => is_string($value),'int' => is_int($value),'float' => is_float($value) || is_int($value),
            'bool' => is_bool($value),'true' => $value === true,'false' => $value === false,'array' => is_array($value),'iterable' => is_iterable($value),'callable' => is_callable($value), default => false,
        };
    }

    private function modelType(?ReflectionType $type): bool
    {
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $part) {
                if ($this->modelType($part)) {
                    return true;
                }
            }
        }

        return $type instanceof ReflectionNamedType && is_a($type->getName(), Model::class, true);
    }

    private function stringType(?ReflectionType $type): bool
    {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $part) {
                if ($this->stringType($part)) {
                    return true;
                }
            }
        }

        return $type instanceof ReflectionNamedType && $type->getName() === 'string';
    }
}
