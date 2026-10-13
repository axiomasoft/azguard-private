<?php

declare(strict_types=1);

namespace AzGuard\Testing;

use AzGuard\Attributes\Api;
use AzGuard\AzGuard;
use AzGuard\Changes\Internal\ChangePipeline;
use AzGuard\Context\Internal\ContextIdentity;
use AzGuard\Contracts\PanelAccess;
use AzGuard\Events\AccessDecided;
use AzGuard\Events\AccessEvent;
use AzGuard\Events\EventType;
use AzGuard\Events\Internal\EventObservers;
use AzGuard\Kernel\Decision\Effect;
use AzGuard\Kernel\Identity\AnyAssignmentScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\Internal\PanelResolver;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\SourceManager;
use AzGuard\Storage\StorageDescriptor;
use AzGuard\Support\ModelKey;
use BackedEnum;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
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
 */
#[Api(since: '1.0')]
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

    // The entry point, mirrored: every method forwards to AzGuard\AzGuard, so the fake stands in for it in a test.

    public function panel(string $id): PanelAccess
    {
        return $this->entry()->panel($id);
    }

    /** @return array<string, Panel> panels by id, in registration order */
    public function panels(): array
    {
        return $this->entry()->panels();
    }

    public function currentPanel(): ?Panel
    {
        return $this->entry()->currentPanel();
    }

    public function sources(): SourceManager
    {
        return $this->entry()->sources();
    }

    /** @param class-string<PanelProvider> $provider */
    public function registerPanel(string $provider): void
    {
        $this->entry()->registerPanel($provider);
    }

    /** @param Closure(PanelBuilder): mixed $callback */
    public function configurePanel(string $id, Closure $callback): void
    {
        $this->entry()->configurePanel($id, $callback);
    }

    /** @param Closure(PanelBuilder): mixed $callback */
    public function configurePanels(Closure $callback): void
    {
        $this->entry()->configurePanels($callback);
    }

    public function check(Model|Authenticatable|SubjectRef $subject, string|UnitEnum $permission, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        return $this->entry()->check($subject, $permission, $on, $guard);
    }

    /** @throws AuthorizationException */
    public function authorize(Model|Authenticatable|SubjectRef $subject, string|UnitEnum $permission, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): void
    {
        $this->entry()->authorize($subject, $permission, $on, $guard);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function withinScope(Model|AssignmentScopeRef $context, Closure $callback): mixed
    {
        return $this->entry()->withinScope($context, $callback);
    }

    public function currentScope(): ?AssignmentScopeRef
    {
        return $this->entry()->currentScope();
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function actingAs(Model|Authenticatable|SubjectRef|string $actor, Closure $callback): mixed
    {
        return $this->entry()->actingAs($actor, $callback);
    }

    public function storage(string $id = 'default'): StorageDescriptor
    {
        return $this->entry()->storage($id);
    }

    /** @return list<StorageDescriptor> */
    public function storages(): array
    {
        return $this->entry()->storages();
    }

    /** A new spy in place of this one, as `AzGuard::fake()`. */
    public function fake(): self
    {
        return $this->entry()->fake();
    }

    private function entry(): AzGuard
    {
        return $this->container->make(AzGuard::class);
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
        $owner = $this->registeredPanel($panel);

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
        $owner = $this->registeredPanel($change->panel);

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
            $owner = $this->registeredPanel($change->panel);
            $on = $owner === null ? null : ContextIdentity::context($owner, $on);
        }

        return $on !== null && $on->equals($context);
    }

    private function registeredPanel(string $id): ?Panel
    {
        return $this->container->make(PanelRegistry::class)->find($id);
    }

    private static function subjectRef(mixed $subject): SubjectRef
    {
        return match (true) {
            $subject instanceof SubjectRef => $subject,
            $subject instanceof Model => SubjectRef::of($subject->getMorphClass(), ModelKey::of($subject)),
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
