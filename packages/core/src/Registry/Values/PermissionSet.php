<?php

declare(strict_types=1);

namespace AzGuard\Registry\Values;

use AzGuard\Contracts\PermissionMatcher;
use AzGuard\Permissions\PermissionKey;
use AzGuard\Registry\Matching\WildcardPermissionMatcher;
use Closure;

/**
 * Immutable set of resolved permission keys for one user+panel.
 * Supports wildcard '*' (SuperAdmin) and patterns like 'app.documents.*'.
 *
 * @api This is stable public API — it is the return type of the GrantSource and
 *      PermissionLayer contracts and of {@see HasPermissions::permissionSet()}.
 *      Import it from this namespace; consumers implementing a custom GrantSource
 *      or PermissionLayer build/return one via the named constructors below.
 *
 * Internals:
 *   - $index: array<string, true>  — O(1) key lookup via isset()
 *   - $wildcard: bool              — pre-computed SuperAdmin flag
 *   - $patterns: list<string>      — wildcard keys only (e.g. 'app.*')
 */
final readonly class PermissionSet
{
    /** Global super-admin wildcard; re-exported from {@see PermissionKey::WILDCARD}. */
    public const string WILDCARD = PermissionKey::WILDCARD;

    /** @var array<string, true> */
    private array $index;

    private bool $wildcard;

    /** @var list<string> Wildcard patterns only (keys containing '*'). */
    private array $patterns;

    /** @param list<string> $keys */
    private function __construct(array $keys)
    {
        $unique = array_unique($keys);
        $this->wildcard = in_array(self::WILDCARD, $unique, strict: true);
        $this->index = array_fill_keys($unique, true);
        $this->patterns = $this->wildcard
            ? []
            : array_values(array_filter($unique, static fn (string $k): bool => str_contains($k, self::WILDCARD)));
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /** @param list<string> $keys */
    public static function fromKeys(array $keys): self
    {
        return new self($keys);
    }

    public static function wildcard(): self
    {
        return new self([self::WILDCARD]);
    }

    /**
     * Build a PermissionSet from raw keys returned by a GrantSource.
     *
     * Centralises the repeated empty / wildcard / fromKeys pattern
     * that previously appeared in every GrantSource implementation.
     *
     * @param  list<string>  $keys
     */
    public static function fromRawKeys(array $keys): self
    {
        if ($keys === []) {
            return self::empty();
        }

        return new self($keys);
    }

    /**
     * Merge two sets. If either is wildcard — result is wildcard.
     */
    public function merge(self $other): self
    {
        if ($this->wildcard || $other->wildcard) {
            return self::wildcard();
        }

        return new self([...array_keys($this->index), ...array_keys($other->index)]);
    }

    /**
     * Exact key match — O(1) via hashmap.
     */
    public function has(string $key): bool
    {
        return $this->wildcard || isset($this->index[$key]);
    }

    /**
     * Wildcard pattern match: 'app.documents.*' covers 'app.documents.view'.
     * Global '*' is handled by $this->wildcard before this is called.
     */
    public function matchesWildcard(string $key): bool
    {
        if ($this->wildcard) {
            return true;
        }

        $matcher = $this->matcher();

        foreach ($this->patterns as $pattern) {
            if ($matcher->matches($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The configured, swappable matcher (memoizes compiled patterns). Falls back
     * to the default grammar when resolved outside a booted container so the VO
     * stays usable standalone.
     */
    private function matcher(): PermissionMatcher
    {
        return app()->bound(PermissionMatcher::class)
            ? app(PermissionMatcher::class)
            : new WildcardPermissionMatcher;
    }

    /**
     * Full check: exact match OR wildcard pattern.
     */
    public function grants(string $key): bool
    {
        // has() already handles wildcard, so no double-check needed.
        if ($this->has($key)) {
            return true;
        }

        return $this->matchesWildcard($key);
    }

    public function isWildcard(): bool
    {
        return $this->wildcard;
    }

    public function isEmpty(): bool
    {
        return $this->index === [];
    }

    /**
     * Filter keys (used for catalog validation).
     */
    public function filter(Closure $callback): self
    {
        return new self(array_keys(array_filter($this->index, $callback, ARRAY_FILTER_USE_KEY)));
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->index);
    }

    public function count(): int
    {
        return count($this->index);
    }
}
