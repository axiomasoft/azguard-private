<?php

function dynamicClassFromConfig(): void
{
    $class = config('azguard.model');

    // ruleid: azguard.dynamic-class
    $model = new $class();

    // ruleid: azguard.dynamic-class
    $class::query();
}

function dynamicClassFromRequest($request): void
{
    $class = $request->input('model');

    // ruleid: azguard.dynamic-class
    $model = new $class;
}

function dynamicClassGuarded(): void
{
    $class = config('azguard.model');

    if (! is_a($class, Model::class, true)) {
        return;
    }

    // ok: azguard.dynamic-class
    $model = new $class();
}

function dynamicClassFromLiteral(): void
{
    $class = Model::class;

    // ok: azguard.dynamic-class
    $model = new $class();
}
