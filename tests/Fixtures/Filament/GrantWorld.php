<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament;

use AzGuard\Changes\GrantFilter;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Filament\Guards\EditorPermission;
use AzGuard\Tests\Fixtures\Filament\Models\User;
use RuntimeException;

/**
 * The admin panel with the grant editors over the editor world: the admin manages `admin`, `seller` and the tenanted
 * `teams` (teams 7 and 8). User 1 edits, user 2 receives grants.
 */
final class GrantWorld
{
    /**
     * @param  list<string>|null  $manages
     */
    public static function prepare(?array $manages = ['admin', 'seller', 'teams']): void
    {
        EditorWorld::prepare();
        FilamentFixture::$editors = ['role_grants' => true, 'permission_grants' => true];
        FilamentFixture::$manages = $manages;
    }

    public static function seed(): void
    {
        EditorWorld::seed();
    }

    /**
     * Signs user 1 in to the `admin` Filament panel with the permissions of both grant editors, or the given ones.
     *
     * @param  list<string>|null  $permissions
     */
    public static function editor(?array $permissions = null): User
    {
        return EditorWorld::editor($permissions ?? self::permissions());
    }

    /**
     * Every permission of the grant editors.
     *
     * @return list<string>
     */
    public static function permissions(): array
    {
        $permissions = [];

        foreach (EditorPermission::cases() as $case) {
            if (str_contains($case->value, '-grants.')) {
                $permissions[] = $case->value;
            }
        }

        return $permissions;
    }

    /** The id of the stored member role grant of a user in a panel and tenant. */
    public static function memberGrant(int $user, string $panel = 'admin', ?int $team = null): string
    {
        $access = AzGuard::panel($panel);
        $access = $team === null ? $access : $access->inTenant(TenantRef::of('team', $team));
        $page = $access->grants()->page(new GrantFilter(kind: 'role', subject: SubjectRef::of('user', $user)));

        return $page->items[0]->id ?? throw new RuntimeException('No member grant of user '.$user.' in '.$panel.'.');
    }
}
