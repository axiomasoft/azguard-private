<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AnyAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Which stored grants of a grant manager a page lists. Panel, tenant and origin are the manager's own and never part of
 * a filter.
 *
 * `kind` is `role`, `permission` or null for both; `context` is one assignment scope (the global one included) or
 * every context of the tenant. `role` and `permission` match the stored key exactly: a permission pattern such as
 * `clients.*` is its own key. `state`:
 * - `active` — the current code still knows what the grant names and its expiry has not passed;
 * - `expired` — known, but its expiry is not after now;
 * - `orphaned` — the role, the assignment scope type or the exact permission is gone, the role is no longer granted
 *   through storage or in that scope type, the exact permission is now decided by its policy, or a pattern covers no
 *   assignable permission; such a grant gives no authority and stays listed for cleanup;
 * - `any` — all of them.
 *
 * `expiresBefore` keeps the grants with an expiry before that moment, so a grant without an expiry never matches it;
 * `grantedBy` keeps the grants whose stored actor is that actor (a system actor matches by type, whatever its reason).
 *
 * Pages are ordered role grants first, then permission grants, each by id. `cursor` continues a page; it belongs to
 * the manager, the filter and the code build it came from.
 *
 * @api
 */
final readonly class GrantFilter
{
    public const string ACTIVE = 'active';

    public const string EXPIRED = 'expired';

    public const string ORPHANED = 'orphaned';

    public const string ANY = 'any';

    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 500;

    /** @throws InvalidArgumentException when the filter contradicts itself or its limit is out of range */
    public function __construct(
        public ?string $kind = null,
        public ?SubjectRef $subject = null,
        public AssignmentScopeRef|AnyAssignmentScope|null $context = null,
        public ?RoleKey $role = null,
        public ?PermissionPattern $permission = null,
        public string $state = self::ANY,
        public int $limit = self::DEFAULT_LIMIT,
        public ?string $cursor = null,
        public ?DateTimeInterface $expiresBefore = null,
        public ?ActorRef $grantedBy = null,
    ) {
        if (! in_array($kind, [null, 'role', 'permission'], true)) {
            throw new InvalidArgumentException('A grant filter kind is "role", "permission" or null.');
        }

        if ($role !== null && ($permission !== null || $kind === 'permission') || $permission !== null && $kind === 'role') {
            throw new InvalidArgumentException('A grant filter names a role or a permission of its own kind.');
        }

        if (! in_array($state, [self::ACTIVE, self::EXPIRED, self::ORPHANED, self::ANY], true)) {
            throw new InvalidArgumentException('A grant filter state is active, expired, orphaned or any.');
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('A grant page holds 1 to '.self::MAX_LIMIT.' grants.');
        }

        if ($cursor !== null && ($cursor === '' || strlen($cursor) > 1024)) {
            throw new InvalidArgumentException('A grant page cursor is a non-empty token from a previous page.');
        }
    }

    /** The same filter continuing after `$cursor`, the `nextCursor` of the page before; null starts again. */
    public function after(?string $cursor): self
    {
        return new self($this->kind, $this->subject, $this->context, $this->role, $this->permission, $this->state, $this->limit, $cursor,
            $this->expiresBefore, $this->grantedBy);
    }

    /**
     * The kinds of grants the filter can match, in page order.
     *
     * @return list<'role'|'permission'>
     */
    public function kinds(): array
    {
        return match (true) {
            $this->role !== null, $this->kind === 'role' => ['role'],
            $this->permission !== null, $this->kind === 'permission' => ['permission'],
            default => ['role', 'permission'],
        };
    }

    /**
     * @internal the conditions of the filter without limit and cursor, to bind a cursor to them
     *
     * @return list<string>
     */
    public function conditions(): array
    {
        return [
            implode(',', $this->kinds()), $this->subject?->key() ?? '*',
            $this->context instanceof AssignmentScopeRef ? $this->context->key() : '*',
            $this->role?->full() ?? '*', $this->permission?->full() ?? '*', $this->state,
            $this->expiresBeforeUtc() ?? '*', $this->grantedBy === null ? '*' : $this->grantedBy->type.':'.($this->grantedBy->id ?? ''),
        ];
    }

    /**
     * @internal the moment of `expiresBefore` in UTC as storage keeps an expiry, or null without one
     */
    public function expiresBeforeUtc(): ?string
    {
        return $this->expiresBefore === null ? null
            : DateTimeImmutable::createFromInterface($this->expiresBefore)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
