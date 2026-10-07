<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Kernel\Decision\StateToken;

/**
 * The outcome of one change operation.
 *
 * `Applied` iff the writer reported an effect. `records` and `effects` are the writer's own; a pipe returns the result
 * it received and cannot replace it. `record` is the dynamic permission of an operation that changed one, otherwise
 * the first grant record. `state` is the resulting revision of this operation; `committed` is false while
 * an outer transaction is still open, so the state must not be cached yet. One `correlationId` per operation.
 *
 * @api
 */
final readonly class ChangeResult
{
    /**
     * @param  list<GrantRecord|PermissionRecord>  $records
     * @param  list<ChangeEffect>  $effects
     */
    private function __construct(
        public ChangeStatus $status,
        public GrantRecord|PermissionRecord|null $record,
        public array $records,
        public array $effects,
        public StateToken $state,
        public bool $committed,
        public string $correlationId,
    ) {}

    /**
     * @internal the writer's result of one change
     *
     * @param  list<ChangeEffect>  $effects
     */
    public static function written(GrantRecord|PermissionRecord|null $record, array $effects, StateToken $state, string $correlationId): self
    {
        return new self($effects === [] ? ChangeStatus::Unchanged : ChangeStatus::Applied, $record,
            $record === null ? [] : [$record], $effects, $state, false, $correlationId);
    }

    /**
     * @internal the operation result over the writer results of its changes, in order
     *
     * @param  list<self>  $results
     */
    public static function combine(array $results, StateToken $state, bool $committed, string $correlationId): self
    {
        $records = $effects = [];
        foreach ($results as $result) {
            array_push($records, ...$result->records);
            array_push($effects, ...$result->effects);
        }

        $primary = $records[0] ?? null;
        foreach ($records as $record) {
            if ($record instanceof PermissionRecord) {
                $primary = $record;

                break;
            }
        }

        return new self($effects === [] ? ChangeStatus::Unchanged : ChangeStatus::Applied, $primary,
            $records, $effects, $state, $committed, $correlationId);
    }

    /**
     * The grants this operation removed, in order: the ids a deletion of a dynamic permission reports once.
     *
     * @return list<string>
     */
    public function removedGrantIds(): array
    {
        $ids = [];
        foreach ($this->effects as $effect) {
            if ($effect->kind === EffectKind::Deleted && $effect->before instanceof GrantRecord) {
                $ids[] = $effect->before->id;
            }
        }

        return $ids;
    }

    public function applied(): bool
    {
        return $this->status === ChangeStatus::Applied;
    }
}
