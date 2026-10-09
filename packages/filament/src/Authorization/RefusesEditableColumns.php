<?php

declare(strict_types=1);

namespace AzGuard\Filament\Authorization;

use Closure;
use Filament\Tables\Columns\Contracts\Editable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Model;
use Livewire\ImplicitlyBoundMethod;
use Throwable;

/**
 * Inline editing is unsupported in guarded tables. Check the final column settings before a Livewire call can write.
 *
 * @internal
 */
final class RefusesEditableColumns
{
    /** @param array<mixed> $params */
    public function __invoke(object $component, string $method, array $params): void
    {
        if (! in_array($method, ['updateTableColumnState', 'callTableColumnMethod', 'callTableColumnAction'], true) || ! $component instanceof HasTable) {
            return;
        }
        $context = FilamentContext::serving();
        $resource = FilamentContext::resourceOf($component);

        if ($context?->enforced() !== true || $resource === null || $context->excludes('resources', $resource)) {
            return;
        }

        try {
            // Use the same binding as Livewire: named, positional and mixed payloads must name the same column/row.
            $bound = ImplicitlyBoundMethod::resolveMethodDependencies(app(), [$component, $method], $params)['named'];
        } catch (Throwable) {
            abort(403);
        }
        $name = $bound[$method === 'updateTableColumnState' ? 'column' : 'name'] ?? null;
        $key = $bound[$method === 'updateTableColumnState' ? 'record' : 'recordKey'] ?? null;

        abort_unless(is_scalar($name) && is_scalar($key), 403);
        $column = $component->getTable()->getColumn((string) $name);

        if ($method === 'callTableColumnAction') {
            // A raw closure never reaches ActionCalling; use an explicitly authorized Action instead.
            abort_if($column?->getAction() instanceof Closure, 403);

            return;
        }

        if (! $column instanceof Editable) {
            return;
        }
        $record = $component->getTableRecord((string) $key);

        if (! $record instanceof Model) {
            return;
        }
        $column->record($record);
        // Exposed column methods may skip disabled(), so do not dispatch those methods even on a disabled editor.
        abort_unless($method === 'updateTableColumnState' && $column->isDisabled(), 403);
    }
}
