<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Exceptions\DefaultPanelConflictException;

/**
 * Turns a sealed recipe into a panel and checks what only the whole set of panels can tell.
 *
 * @phpstan-import-type Record from PanelRecipe
 */
final class PanelCompiler
{
    public function compile(PanelRecipe $recipe): Panel
    {
        $id = $recipe->panelId();
        $label = $this->scalar($recipe, PanelRecipe::LABEL);
        $prefix = $this->scalar($recipe, PanelRecipe::RESOURCE_PREFIX) ?? true;

        return new Panel(
            id: $id,
            label: is_string($label) ? $label : $id,
            default: $this->scalar($recipe, PanelRecipe::DEFAULT) === true,
            prefix: match (true) {
                is_string($prefix) => $prefix,
                $prefix === false => null,
                default => $id,
            },
            subjectModels: array_values(array_unique(array_column($recipe->subjects(), 'model'))),
        );
    }

    /**
     * @param  array<string, Panel>  $panels
     *
     * @throws DefaultPanelConflictException when two default panels share a subject model
     */
    public function assertDefaults(array $panels): void
    {
        $defaults = array_values(array_filter($panels, static fn (Panel $panel): bool => $panel->isDefault()));

        foreach ($defaults as $position => $first) {
            foreach (array_slice($defaults, $position + 1) as $second) {
                $shared = $this->sharedModel($first, $second);

                if ($shared !== null) {
                    throw new DefaultPanelConflictException(
                        'Panels "'.$first->id().'" and "'.$second->id().'" are both the default panel of '.$shared
                        .': keep default() on one of them.',
                    );
                }
            }
        }
    }

    /**
     * The value of a scalar setting: the highest layer that sets it wins, inside a layer the last record wins.
     */
    private function scalar(PanelRecipe $recipe, string $setting): mixed
    {
        $winner = null;

        foreach ($recipe->layered($setting) as $record) {
            if ($winner !== null && PanelRecipe::rank($record['origin']) !== PanelRecipe::rank($winner['origin'])) {
                break;
            }

            $winner = $record;
        }

        return $winner['value'] ?? null;
    }

    private function sharedModel(Panel $first, Panel $second): ?string
    {
        foreach ($first->subjectModels() as $model) {
            foreach ($second->subjectModels() as $other) {
                if (is_a($model, $other, true) || is_a($other, $model, true)) {
                    return is_a($model, $other, true) ? $model : $other;
                }
            }
        }

        return null;
    }
}
