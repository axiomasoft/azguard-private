<?php

declare(strict_types=1);

namespace AzGuard\Filament\Contracts;

use AzGuard\Schema\FieldTarget;
use AzGuard\Schema\PanelSchema;
use Filament\Schemas\Components\Component;

/**
 * A Filament component of its own for a grant field that the schema of a panel declares, such as a tree of departments
 * instead of a plain input.
 *
 * The extension only replaces the component of a declared field: a component for a name the schema does not declare
 * is a configuration error, and what the component collects is checked by the writer of the panel like any other value.
 * Register it with `AzGuardPlugin::formExtensions()`; it is resolved from the container every time a form is built,
 * so it keeps no state between panels or requests.
 *
 * @api
 */
interface FilamentFormExtension
{
    public function appliesTo(PanelSchema $schema, FieldTarget $target): bool;

    /**
     * The components by the name of the schema field they collect; each one must be a form field with that name.
     *
     * @return array<string, Component>
     */
    public function components(PanelSchema $schema, FieldTarget $target): array;
}
