<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\RoleKey;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A role held in a scope, contributed by a source; qualified by scope, expiry and fields before expansion.
 */
final readonly class RoleContribution
{
    /**
     * @param  array<non-empty-string, mixed>  $fields
     */
    private function __construct(
        public RoleKey $role,
        public AccessScope $scope,
        public string $source,
        public string $origin,
        public ?DateTimeImmutable $expiresAt,
        private array $fields,
    ) {}

    /**
     * @param  array<mixed>  $fields  decision fields of this one contribution
     *
     * @throws InvalidIdentityException when the source or origin is not a valid label
     * @throws InvalidArgumentException when the fields are not plain data
     */
    public static function of(
        RoleKey $role,
        AccessScope $scope,
        string $source,
        string $origin = IdentityCodec::DEFAULT_ORIGIN,
        ?DateTimeImmutable $expiresAt = null,
        array $fields = [],
    ): self {
        IdentityCodec::assertSourceLabel($source);
        IdentityCodec::assertSourceLabel($origin);

        return new self($role, $scope, $source, $origin, $expiresAt, Grant::assertFields($fields));
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function activeAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt === null || $this->expiresAt > $now;
    }
}
