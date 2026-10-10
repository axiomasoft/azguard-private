<?php

declare(strict_types=1);

namespace AzGuard\Storage;

use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use Closure;
use Illuminate\Database\Query\Builder;

final class StorageMutation
{
    private bool $active = true;

    /** @var array<string, true> */
    private array $touched = [];

    /** @var list<Closure(): void> */
    private array $callbacks = [];

    /** @param array<string, PanelState> $states */
    public function __construct(private readonly Storage $storage, private readonly array $states, private readonly bool $nested) {}

    public function state(string $panel): PanelState
    {
        $this->assertActive();

        return $this->states[$panel] ?? throw new UnknownPanelException('Panel '.$panel.' is outside this mutation.');
    }

    public function table(string $base): Builder
    {
        $this->assertActive();

        return $this->storage->table($base);
    }

    public function touch(string $panel): void
    {
        $this->state($panel);
        $this->touched[$panel] = true;
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
