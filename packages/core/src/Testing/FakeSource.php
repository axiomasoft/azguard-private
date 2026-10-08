<?php

declare(strict_types=1);

namespace AzGuard\Testing;

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Scopes\ModelIdentity;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A source of grants that lives in memory, for a test of the application: the test says who holds which permission or
 * role and the real engine decides from that, with the same scope, expiry and role rules as for any other source. It
 * is a source like the others, not a shortcut around the engine.
 *
 * @api
 */
final class FakeSource implements ProvidesGrants, ProvidesRoleGrants
{
    /** @var list<array{subject: string, pattern: PermissionPattern, scope: AccessScope, until: ?DateTimeImmutable}> */
    private array $permissions = [];

    /** @var list<array{subject: string, role: RoleKey, scope: AccessScope, until: ?DateTimeImmutable}> */
    private array $roles = [];

    public function __construct(private readonly string $id = 'fake') {}

    public function id(): string
    {
        return $this->id;
    }

    /** Grants a permission or a pattern of the panel, tenant-wide when no scope is given. */
    public function grantPermission(Model|SubjectRef $subject, PermissionPattern $permission, ?AssignmentScopeRef $on = null,
        ?TenantRef $tenant = null, ?DateTimeImmutable $until = null): self
    {
        $this->permissions[] = ['subject' => self::key($subject), 'pattern' => $permission,
            'scope' => AccessScope::in($tenant ?? TenantRef::global(), $on), 'until' => $until];

        return $this;
    }

    public function grantRole(Model|SubjectRef $subject, RoleKey $role, ?AssignmentScopeRef $on = null,
        ?TenantRef $tenant = null, ?DateTimeImmutable $until = null): self
    {
        $this->roles[] = ['subject' => self::key($subject), 'role' => $role,
            'scope' => AccessScope::in($tenant ?? TenantRef::global(), $on), 'until' => $until];

        return $this;
    }

    /** Forgets every grant made on this source. */
    public function clear(): self
    {
        $this->permissions = $this->roles = [];

        return $this;
    }

    public function volatility(): Volatility
    {
        return Volatility::Volatile;
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $panel = $context->panel()->id();

        foreach ($this->permissions as $held) {
            if ($held['subject'] === $subject->key() && $held['pattern']->panel() === $panel && self::covers($scopes, $held['scope'])) {
                yield Grant::of($held['pattern'], $this->id, $held['scope'], expiresAt: $held['until']);
            }
        }
    }

    public function roleGrants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        $panel = $context->panel()->id();

        foreach ($this->roles as $held) {
            if ($held['subject'] === $subject->key() && $held['role']->panel() === $panel && self::covers($scopes, $held['scope'])) {
                yield RoleContribution::of($held['role'], $held['scope'], $this->id, expiresAt: $held['until']);
            }
        }
    }

    /** @param list<AccessScope> $scopes */
    private static function covers(array $scopes, AccessScope $held): bool
    {
        foreach ($scopes as $scope) {
            if ($scope->equals($held)) {
                return true;
            }
        }

        return false;
    }

    private static function key(Model|SubjectRef $subject): string
    {
        return $subject instanceof SubjectRef ? $subject->key() : SubjectRef::of($subject->getMorphClass(), ModelIdentity::key($subject))->key();
    }
}
