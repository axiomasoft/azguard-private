<?php

declare(strict_types=1);

namespace AzGuard\Registry\Resolver;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Canonical persisted subject identity for cache keys and invalidation.
 *
 * @internal
 */
final readonly class SubjectIdentity
{
    private function __construct(
        public string $morphType,
        public string $id,
    ) {}

    public static function fromAuthenticatable(Authenticatable $subject): self
    {
        $morphType = $subject instanceof Model
            ? $subject->getMorphClass()
            : (method_exists($subject, 'getMorphClass')
                ? $subject->getMorphClass()
                : $subject::class);

        return new self($morphType, self::canonicalId($subject->getAuthIdentifier()));
    }

    public static function fromPersisted(string $morphType, int|string|null $id): self
    {
        return new self($morphType, self::canonicalId($id ?? ''));
    }

    public function digest(): string
    {
        return self::digestPayload([2, $this->morphType, $this->id]);
    }

    public function scopedRolesRequestKey(string $entityMorphClass): string
    {
        return self::digestPayload([2, 'scoped', $this->digest(), $entityMorphClass]);
    }

    public function equals(self $other): bool
    {
        return $this->morphType === $other->morphType && $this->id === $other->id;
    }

    /**
     * @param  list<mixed>  $payload
     */
    public static function digestPayload(array $payload): string
    {
        $material = json_encode($payload, JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode(hash('sha256', $material, true)), '+/', '-_'), '=');
    }

    private static function canonicalId(int|string|null $id): string
    {
        return (string) $id;
    }
}
