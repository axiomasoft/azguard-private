<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Kernel\Decision\StateToken;

/**
 * The outcome of one change operation.
 *
 * `Applied` iff the writer reported an effect. `records` and `effects` are the writer's own; a pipe returns the result
 * it received and cannot replace it. `state` is the resulting revision of this operation; `committed` is false while
 * an outer transaction is still open, so the state must not be cached yet. One `correlationId` per operation.
 *
 * @api
 */
final readonly class ChangeResult
{
    /**
     * @param  list<GrantRecord>  $records
     * @param  list<ChangeEffect>  $effects
     */
    private function __construct(
        public ChangeStatus $status,
        public ?GrantRecord $record,
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
    public static function written(?GrantRecord $record, array $effects, StateToken $state, string $correlationId): self
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

        return new self($effects === [] ? ChangeStatus::Unchanged : ChangeStatus::Applied, $records[0] ?? null,
            $records, $effects, $state, $committed, $correlationId);
    }

    public function applied(): bool
    {
        return $this->status === ChangeStatus::Applied;
    }
}
