<?php

// Source: anonymized production Laravel project

declare(strict_types=1);

namespace App\Dto\Document\Repository;

/**
 * Delta of member changes after sync-operations write-side repository.
 * Location: app/Dto/<Domain>/Repository/ — DTO belongs to the repository contract.
 *
 * @phpstan-type MemberChange array{role: string, user_id: int}
 */
final readonly class MembersDelta
{
    /**
     * @param  array<int, MemberChange>  $added
     * @param  array<int, MemberChange>  $removed
     */
    public function __construct(
        public array $added,
        public array $removed,
    ) {}

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->removed === [];
    }

    /**
     * @return array{added: array<int, MemberChange>, removed: array<int, MemberChange>}
     */
    public function toArray(): array
    {
        return [
            'added' => $this->added,
            'removed' => $this->removed,
        ];
    }
}
