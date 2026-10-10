<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use DateTimeImmutable;

/**
 * A grant from any source: "the permission holds because of this", with its owner (origin), scope and expiry.
 */
final readonly class Grant
{
    /**
     * @param  array<non-empty-string, mixed>  $fields
     */
    private function __construct(
        public PermissionPattern $pattern,
        public string $source,
        public ?RoleKey $role,
        public AccessScope $scope,
        public string $origin,
        public ?DateTimeImmutable $expiresAt,
        private array $fields,
    ) {}

    /**
     * @param  array<mixed>  $fields  decision fields of this one grant
     *
     * @throws InvalidIdentityException when the source or origin is not a valid label
     * @throws InvalidSourceContributionException when the role belongs to another panel or the fields are not plain data
     */
    public static function of(
        PermissionPattern $pattern,
        string $source,
        AccessScope $scope,
        ?RoleKey $role = null,
        string $origin = IdentityCodec::DEFAULT_ORIGIN,
        ?DateTimeImmutable $expiresAt = null,
        array $fields = [],
    ): self {
        IdentityCodec::assertSourceLabel($source);
        IdentityCodec::assertSourceLabel($origin);

        if ($role !== null && $role->panel() !== $pattern->panel()) {
            throw new InvalidSourceContributionException(sprintf(
                'Grant role "%s" belongs to another panel than pattern "%s".',
                $role->full(),
                $pattern->full(),
            ));
        }

        return new self($pattern, $source, $role, $scope, $origin, $expiresAt, self::assertFields($fields));
    }

    /**
     * Copies validated decision fields as plain data, detaching references so they remain safe to cache.
     *
     * @param  array<mixed>  $fields
     * @return array<non-empty-string, mixed>
     *
     * @throws InvalidSourceContributionException
     */
    public static function assertFields(array $fields): array
    {
        $plain = [];

        foreach ($fields as $key => $value) {
            if (! is_string($key) || $key === '') {
                throw new InvalidSourceContributionException('Decision field names must be non-empty strings.');
            }

            $plain[$key] = self::assertPlain($value, $key);
        }

        return $plain;
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

    private static function assertPlain(mixed $value, string $path): mixed
    {
        if (is_array($value)) {
            $plain = [];

            foreach ($value as $key => $nested) {
                $plain[$key] = self::assertPlain($nested, $path.'.'.$key);
            }

            return $plain;
        }

        if ($value !== null && ! is_scalar($value)) {
            throw new InvalidSourceContributionException(sprintf(
                'Decision field "%s" must be a scalar, null or array, got %s.',
                $path,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
