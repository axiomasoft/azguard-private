<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Fields;

use AzGuard\Filament\Contracts\FilamentFormExtension;
use AzGuard\Schema\FieldTarget;
use AzGuard\Schema\PanelSchema;
use Filament\Forms\Components\Textarea;

/** A text area for the reason of a grant of any kind, labelled with the panel it was built for. */
final class ReasonExtension implements FilamentFormExtension
{
    public function appliesTo(PanelSchema $schema, FieldTarget $target): bool
    {
        return true;
    }

    public function components(PanelSchema $schema, FieldTarget $target): array
    {
        return ['reason' => Textarea::make('reason')->label('Reason in '.$schema->panel)];
    }
}
