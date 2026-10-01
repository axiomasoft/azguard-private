<?php

declare(strict_types=1);

namespace AzGuard\Concerns;

use AzGuard\Registry\Resolver\PermissionStateRevision;

/**
 * Keep individual grant model writes and their revision fence in one DB transaction.
 * Query-builder bulk writes do not call these methods and require an explicit reset.
 *
 * @internal
 */
trait RevisionedPermissionModelWrites
{
    public function save(array $options = []): bool
    {
        $state = app(PermissionStateRevision::class);
        $state->assertSameConnection($this);

        if ($state->insideMutation()) {
            $wasNew = ! $this->exists;
            $original = $this->getRawOriginal();
            $saved = parent::save($options);

            if ($saved && ($wasNew || $original != $this->getAttributes())) {
                $state->markChanged();
            }

            return $saved;
        }

        return $state->mutate(function () use ($options): array {
            $wasNew = ! $this->exists;
            $original = $this->getRawOriginal();
            $saved = parent::save($options);

            return [$saved, $saved && ($wasNew || $original != $this->getAttributes())];
        });
    }

    public function delete(): ?bool
    {
        if (! $this->exists) {
            return parent::delete();
        }

        $state = app(PermissionStateRevision::class);
        $state->assertSameConnection($this);

        if ($state->insideMutation()) {
            $deleted = parent::delete();

            if ($deleted === true) {
                $state->markChanged();
            }

            return $deleted;
        }

        return $state->mutate(function (): array {
            $deleted = parent::delete();

            return [$deleted, $deleted === true];
        });
    }
}
