<?php

declare(strict_types=1);

use AzGuard\Plugins\PluginContext;

it('is a final readonly value with build metadata only', function (): void {
    $context = new ReflectionClass(PluginContext::class);
    $methods = [];

    foreach ($context->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (! $method->isConstructor()) {
            $methods[$method->getName()] = (string) $method->getReturnType();
        }
    }

    expect($context->isFinal())->toBeTrue()
        ->and($context->isReadOnly())->toBeTrue()
        ->and($methods)->toBe(['panelId' => 'string', 'pluginId' => 'string', 'buildId' => 'string', 'dependencies' => 'array'])
        ->and(array_filter($context->getProperties(), static fn (ReflectionProperty $property): bool => $property->isPublic()))->toBe([]);

    foreach (['namespace', 'options', 'option', 'container', 'panel', 'get'] as $method) {
        expect($context->hasMethod($method))->toBeFalse($method);
    }
});

it('holds scalars and a list of ids, never an object', function (): void {
    $types = array_map(
        static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
        (new ReflectionMethod(PluginContext::class, '__construct'))->getParameters(),
    );

    expect($types)->toBe(['string', 'string', 'string', 'array']);
});

it('returns what the build gave it', function (): void {
    $context = new PluginContext('admin', 'acme/reports', 'build-7', ['acme/audit-trail']);

    expect($context->panelId())->toBe('admin')
        ->and($context->pluginId())->toBe('acme/reports')
        ->and($context->buildId())->toBe('build-7')
        ->and($context->dependencies())->toBe(['acme/audit-trail']);
});
