<?php

declare(strict_types=1);

namespace App\Domain\Dto;

use App\Domain\ValueObject\Email;

/**
 * DTO («naked», without spatie/laravel-data): structure for transferring data between layers.
 * - final readonly, promoted constructor properties — typed contract;
 * - NO behavior and NO invariants beyond types (this is the difference from VO);
 * - NO infrastructure dependencies (Request, Eloquent, DB);
 * - is created by named arguments (see php/named-arguments);
 * - suffix Data (data) or Form / Command (command input) — see php/naming-conventions.
 *
 * When you need validation-from-query, castes, #[TypeScript], DataCollection —
 * this spatie/laravel-data (see php/laravel-data), not this one «naked» DTO.
 */
final readonly class CreateUserData
{
    public function __construct(
        public Email $email,       // field can be VO — DTO transfers an already valid value
        public string $name,
        public ?string $locale = null,
    ) {}
}

/**
 * Delta-DTO: result sync-operations (who was added / deleted).
 * Typical return store-repository to Action knew who to notify,
 * without re-reading the database (see php/repositories).
 */
final readonly class Delta
{
    /**
     * @param  list<int>  $added
     * @param  list<int>  $removed
     */
    public function __construct(
        public array $added,
        public array $removed,
    ) {}
}
