<?php

declare(strict_types=1);

namespace AzGuard\Testing;

use AzGuard\Changes\ChangePipeline;
use AzGuard\Events\AccessDecided;
use AzGuard\Events\AccessEvent;
use AzGuard\Events\EventObservers;
use AzGuard\Events\EventType;
use AzGuard\Kernel\Decision\Effect;
use AzGuard\Kernel\Identity\AnyAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelResolver;
use AzGuard\Scopes\ModelIdentity;
use BackedEnum;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Throwable;
use UnitEnum;

/**
 * A spy over the real engine: it records the checks and the changes the application makes and asserts on them. It
 * decides nothing, replaces no check and has no allow-all mode — a denied permission stays denied. What it records
 * comes from the events the package publishes, also for a panel that does not trace decisions and also when the
 * application fakes its own events.
 *
 * `$on` of the change assertions names the assignment scope of the grant: `null` is the tenant-wide grant,
 * `AnyAssignmentScope::all()` is a grant in any scope.
 *
 * @api
 */
final class AzGuardFake
{
    /** @var list<RecordedCheck> */
    private array $checks = [];

    /** @var list<RecordedChange> */
    private array $changes = [];

    private ?int $handle;

    public function __construct(private readonly Container $container)
    {
        $this->handle = $container->make(EventObservers::class)->add($this->record(...));
    }

    /** Stops recording; what was recorded stays readable. */
    public function stop(): void
    {
        if ($this->handle !== null) {
            $this->container->make(EventObservers::class)->forget($this->handle);
            $this->handle = null;
        }
    }

    /** @return list<RecordedCheck> */
    public function checks(): array
    {
        return $this->checks;
    }

    /** @return list<RecordedChange> */
    public function changes(): array
    {
        return $this->changes;
    }

    /** The permission was checked, `$times` times when given. */
    public function assertChecked(string|UnitEnum $permission, ?int $times = null): void
    {
        $found = count(array_filter($this->checks, fn (RecordedCheck $check): bool => $this->isPermission($check->panel, $check->permission->full(), $permission)));

        Assert::assertTrue(
            $times === null ? $found > 0 : $found === $times,
            $times === null
                ? 'Failed asserting that '.self::name($permission).' was checked. '.$this->checked()
                : 'Failed asserting that '.self::name($permission).' was checked '.$times.' time(s), it was checked '.$found.'. '.$this->checked(),
        );
    }

    public function assertNotChecked(string|UnitEnum $permission): void
    {
        $found = array_filter($this->checks, fn (RecordedCheck $check): bool => $this->isPermission($check->panel, $check->permission->full(), $permission));

        Assert::assertSame([], array_values($found), 'Failed asserting that '.self::name($permission).' was not checked. '.$this->checked());
    }

    /** The engine decided the check of the subject with this effect. */
    public function assertDecided(mixed $subject, string|UnitEnum $permission, Effect $effect): void
    {
        $subject = self::subjectRef($subject);
        $found = array_filter($this->checks, fn (RecordedCheck $check): bool => $check->subject->equals($subject)
            && $check->effect === $effect && $this->isPermission($check->panel, $check->permission->full(), $permission));

        Assert::assertNotSame([], $found, 'Failed asserting that '.self::name($permission).' was decided as '.$effect->value.' for '.$subject->key().'. '.$this->checked());
    }

    public function assertRoleGranted(mixed $subject, string|UnitEnum $role, Model|AssignmentScopeRef|AnyAssignmentScope|null $on = null): void
    {
        $this->assertRoleChange($subject, $role, $on, EventType::RoleGranted, 'granted');
    }

    public function assertRoleRevoked(mixed $subject, string|UnitEnum $role, Model|AssignmentScopeRef|AnyAssignmentScope|null $on = null): void
    {
        $this->assertRoleChange($subject, $role, $on, EventType::RoleRevoked, 'revoked');
    }

    public function assertPermissionGranted(mixed $subject, string|UnitEnum $permission, Model|AssignmentScopeRef|AnyAssignmentScope|null $on = null): void
    {
        $this->assertPermissionChange($subject, $permission, $on, EventType::PermissionGranted, 'granted');
    }

    public function assertPermissionRevoked(mixed $subject, string|UnitEnum $permission, Model|AssignmentScopeRef|AnyAssignmentScope|null $on = null): void
    {
        $this->assertPermissionChange($subject, $permission, $on, EventType::PermissionRevoked, 'revoked');
    }

    private function record(AccessEvent $event): void
    {
        if ($event instanceof AccessDecided) {
            $this->checks[] = RecordedCheck::of($event);

            return;
        }
        $this->changes[] = RecordedChange::of($event);
    }

    private function assertRoleChange(mixed $subject, string|UnitEnum $role, Model|AssignmentScopeRef|AnyAssignmentScope|null $on, EventType $type, string $verb): void
    {
        $subject = self::subjectRef($subject);
        $found = array_filter($this->changes, fn (RecordedChange $change): bool => $change->type === $type && $change->role !== null
            && $change->subject?->equals($subject) === true && $this->isRole($change, $role) && $this->inScope($change, $on));

        Assert::assertNotSame([], $found, 'Failed asserting that role '.self::name($role).' was '.$verb.' for '.$subject->key().'. '.$this->changed());
    }

    private function assertPermissionChange(mixed $subject, string|UnitEnum $permission, Model|AssignmentScopeRef|AnyAssignmentScope|null $on, EventType $type, string $verb): void
    {
        $subject = self::subjectRef($subject);
        $found = array_filter($this->changes, fn (RecordedChange $change): bool => $change->type === $type && $change->permission !== null
            && $change->subject?->equals($subject) === true && $this->isPermission($change->panel, $change->permission->full(), $permission) && $this->inScope($change, $on));

        Assert::assertNotSame([], $found, 'Failed asserting that permission '.self::name($permission).' was '.$verb.' for '.$subject->key().'. '.$this->changed());
    }

    /** Whether the recorded full name is the permission, named by an enum case, a full name or a name of the panel. */
    private function isPermission(string $panel, string $recorded, string|UnitEnum $permission): bool
    {
        $owner = $this->panel($panel);

        if ($owner === null) {
            return false;
        }

        try {
            return $this->container->make(PanelResolver::class)->pattern($owner, $permission)->full() === $recorded;
        } catch (Throwable) {
            return false;
        }
    }

    private function isRole(RecordedChange $change, string|UnitEnum $role): bool
    {
        $owner = $this->panel($change->panel);

        if ($owner === null || $change->role === null) {
            return false;
        }

        try {
            $name = match (true) {
                $role instanceof BackedEnum => (string) $role->value,
                $role instanceof UnitEnum => $role->name,
                default => $role,
            };

            return $this->container->make(ChangePipeline::class)->role($owner, $name)->equals($change->role);
        } catch (Throwable) {
            return false;
        }
    }

    private function inScope(RecordedChange $change, Model|AssignmentScopeRef|AnyAssignmentScope|null $on): bool
    {
        $context = $change->context ?? AssignmentScopeRef::global();

        if ($on instanceof AnyAssignmentScope) {
            return true;
        }

        if ($on === null) {
            return $context->isGlobal();
        }

        if ($on instanceof Model) {
            $owner = $this->panel($change->panel);
            $on = $owner === null ? null : ModelIdentity::context($owner, $on);
        }

        return $on !== null && $on->equals($context);
    }

    private function panel(string $id): ?Panel
    {
        return $this->container->make(PanelRegistry::class)->find($id);
    }

    private static function subjectRef(mixed $subject): SubjectRef
    {
        return match (true) {
            $subject instanceof SubjectRef => $subject,
            $subject instanceof Model => SubjectRef::of($subject->getMorphClass(), ModelIdentity::key($subject)),
            default => throw new InvalidArgumentException(get_debug_type($subject).' is not a subject: pass an Eloquent model or a subject reference.'),
        };
    }

    private static function name(string|UnitEnum $name): string
    {
        return $name instanceof UnitEnum ? $name::class.'::'.$name->name : $name;
    }

    private function checked(): string
    {
        return $this->checks === [] ? 'No check was recorded.' : 'Recorded checks: '.implode(', ', array_map(
            static fn (RecordedCheck $check): string => $check->subject->key().' '.$check->permission->full().' '.$check->effect->value,
            $this->checks,
        )).'.';
    }

    private function changed(): string
    {
        return $this->changes === [] ? 'No change was recorded.' : 'Recorded changes: '.implode(', ', array_map(
            static fn (RecordedChange $change): string => $change->type->value.' '.($change->subject?->key() ?? '-').' '
                .($change->role?->full() ?? $change->permission?->full() ?? '-'),
            $this->changes,
        )).'.';
    }
}
