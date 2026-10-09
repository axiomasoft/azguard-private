<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Fields;

use AzGuard\Filament\Contracts\FilamentFormExtension;
use AzGuard\Schema\FieldTarget;
use AzGuard\Schema\PanelSchema;
use Filament\Forms\Components\TextInput;

/** An extension that gives a component for a field no schema declares. */
final class StrayFieldExtension implements FilamentFormExtension
{
    public function appliesTo(PanelSchema $schema, FieldTarget $target): bool
    {
        return true;
    }

    public function components(PanelSchema $schema, FieldTarget $target): array
    {
        return ['approved_by' => TextInput::make('approved_by')];
    }
}
