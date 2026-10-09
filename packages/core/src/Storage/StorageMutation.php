<?php

declare(strict_types=1);

namespace AzGuard\Storage;

use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Storage\Schema\HostKeyColumns;
use Closure;
use Illuminate\Database\Query\Builder;

final class StorageMutation
{
    private bool $active = true;

    /** @var array<string, true> */
    private array $touched = [];

    /** @var array<string, true> */
    private array $epochs = [];

    /** @var array<string, array<string, array{string, string}>> panel => subject key => [type, canonical id] */
    private array $subjects = [];

    /** @var list<Closure(): void> */
    private array $callbacks = [];

    /** @param array<string, PanelState> $states */
    public function __construct(private readonly Storage $storage, private array $states, private readonly bool $nested) {}

    public function state(string $panel): PanelState
    {
        $this->assertActive();

        return $this->states[$panel] ?? throw new UnknownPanelException('Panel '.$panel.' is outside this mutation.');
    }

    /** Whether this active mutation locked the panel. */
    public function holds(string $panel): bool
    {
        return $this->active && isset($this->states[$panel]);
    }

    public function table(string $base): Builder
    {
        $this->assertActive();

        return $this->storage->table($base);
    }

    /**
     * Marks a change of the panel whose subjects are not named (a manual touch, a reset, a dynamic permission, a
     * write of the host through this mutation): the root commit raises the version and the epoch, so every cached
     * contribution of the panel is read again.
     */
    public function touch(string $panel): void
    {
        $this->state($panel);
        $this->touched[$panel] = true;
        $this->epochs[$panel] = true;
    }

    /**
     * Marks a change of one subject's grants: the root commit raises the panel version and the revision of that
     * subject only (audits/2026-10-09-consistency-design.md, step 3).
     */
    public function touchSubject(string $panel, SubjectRef $subject): void
    {
        $this->state($panel);
        $this->touched[$panel] = true;
        $id = HostKeyColumns::canonical($this->storage->hostKeys(), $subject->id());
        $this->subjects[$panel][IdentityCodec::compose([$subject->type(), $id])] = [$subject->type(), $id];
    }

    /**
     * @internal Marks of a nested mutation of a panel this one holds.
     *
     * @param  array<string, array{string, string}>  $subjects
     */
    public function absorb(string $panel, bool $epoch, array $subjects): void
    {
        $this->state($panel);
        $this->touched[$panel] = true;

        if ($epoch) {
            $this->epochs[$panel] = true;
        }
        $this->subjects[$panel] = ($this->subjects[$panel] ?? []) + $subjects;
    }

    public function touchesEpoch(string $panel): bool
    {
        $this->assertActive();

        return isset($this->epochs[$panel]);
    }

    /** @return array<string, array{string, string}> */
    public function touchedSubjects(string $panel): array
    {
        $this->assertActive();

        return $this->subjects[$panel] ?? [];
    }

    /** @internal The storage renewed the incarnation of a panel this mutation locked. */
    public function renewed(PanelState $state): void
    {
        $this->state($state->panel);
        $this->states[$state->panel] = $state;
    }

    /** @param Closure(): void $callback */
    public function afterCommit(Closure $callback): void
    {
        $this->assertActive();
        $this->callbacks[] = $callback;
    }

    public function isNested(): bool
    {
        $this->assertActive();

        return $this->nested;
    }

    /** @return list<string> */
    public function touched(): array
    {
        $this->assertActive();

        return array_keys($this->touched);
    }

    /** @return list<Closure(): void> */
    public function callbacks(): array
    {
        $this->assertActive();

        return $this->callbacks;
    }

    public function close(): void
    {
        $this->assertActive();
        $this->active = false;
    }

    private function assertActive(): void
    {
        if (! $this->active) {
            throw new UnsupportedDirectWriteException('The storage mutation has already returned.');
        }
    }
}
