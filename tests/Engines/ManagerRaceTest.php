<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Changes\PanelManagers;
use AzGuard\Exceptions\StaleSelectionException;
use AzGuard\Exceptions\UnknownRoleException;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Engines\Support\CacheEngineWorld;
use AzGuard\Tests\Engines\Support\Processes;
use AzGuard\Tests\Feature\Changes\Managers\ManagerWorld as M;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/*
 * Races of the managers and the role key migration on PostgreSQL/MySQL/MariaDB with independent processes. A worker
 * holds the panel lock inside its first pipe until released; the competitor is observed in a server lock wait first.
 */

function managerRaceDrop(): void
{
    $schema = CrmWorld::storage()->connection()->getSchemaBuilder();
    foreach (['clients', 'project_members', 'projects', 'organization_user', 'users', 'cities', 'organizations'] as $table) {
        $schema->dropIfExists($table);
    }
    app(StorageSchema::class)->drop('default');
}

function managerRaceBarrier(string $name): string
{
    return sys_get_temp_dir().'/azguard-manager-race-'.bin2hex(random_bytes(6)).'-'.$name;
}

/**
 * Runs `$first` holding the panel lock, starts `$second`, observes it waiting for that lock, then releases.
 *
 * @param  array<string, mixed>  $first
 * @param  array<string, mixed>  $second
 * @return array{array<string, mixed>, array<string, mixed>}
 */
function managerRacePair(Processes $processes, array $first, array $second): array
{
    $ready = managerRaceBarrier('ready');
    $release = managerRaceBarrier('release');
    $id = managerRaceBarrier('id');

    try {
        $holder = $processes->start([...$first, 'ready' => $ready, 'release' => $release], 'change-worker.php');
        Processes::wait($ready);
        $waiter = $processes->start([...$second, 'id_file' => $id], 'change-worker.php');
        Processes::wait($id);
        CacheEngineWorld::waitForLock((int) file_get_contents($id));
        touch($release);

        return [$processes->finish($holder), $processes->finish($waiter)];
    } finally {
        foreach ([$ready, $release, $id] as $file) {
            @unlink($file);
        }
    }
}

/** @return list<string> stored role keys of the tenant A rows of former key or current key, by id */
function managerRaceRoles(): array
{
    return array_map(fn (array $row): string => $row['id'].'|'.$row['role'].'|'.($row['expires_at'] ?? '∞'),
        array_values(array_filter(W::rows(), fn (array $row): bool => in_array($row['role'], ['inspector', 'auditor'], true)
            && $row['tenant_key'] === 'crm.organization:1')));
}

beforeEach(function (): void {
    managerRaceDrop();
    CrmWorld::seed();
    $this->processes = new Processes;
});
afterEach(function (): void {
    managerRaceDrop();
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('C13 lets one manager update ∥ update with the same form fingerprint win and refuses the stale form', function (): void {
    // The fingerprint covers the build: read the form on the build shape the workers compile (one closure pipe).
    $panel = W::panel([static fn (Change $c, Closure $next) => $next($c)]);
    $id = W::grant($panel, 'auditor', 2, null)->record?->id ?? '';
    $form = PanelManagers::for($panel, W::tenant())->grants()->find($id);
    [$first, $second] = managerRacePair($this->processes,
        ['op' => 'manager-update', 'id' => $id, 'fingerprint' => $form?->fingerprint, 'fields' => ['eligible' => true]],
        ['op' => 'manager-update', 'id' => $id, 'fingerprint' => $form?->fingerprint, 'fields' => ['eligible' => false]]);

    expect($first['status'])->toBe('applied')
        ->and($second['error'])->toBe(StaleSelectionException::class)
        ->and(json_decode((string) M::row($id)['meta'], true))->toBe(['eligible' => true]);
})->group('engines');

it('R66 serializes migration ∥ grant(former key): the grant is refused in both orders and no former key row is left', function (bool $migrationFirst): void {
    $valid = M::stored('role', 'inspector', 2);
    $expired = M::stored('role', 'inspector', 3, origin: 'import', until: '2026-01-01 00:00:00');
    $orphan = M::stored('role', 'inspector', 2, 'crm.project:3');
    $migrate = ['op' => 'migrate', 'from' => 'inspector', 'to' => 'auditor'];
    $grant = ['op' => 'grant', 'role' => 'inspector', 'user' => 1];
    $version = W::version();
    [$first, $second] = managerRacePair($this->processes, $migrationFirst ? $migrate : $grant, $migrationFirst ? $grant : $migrate);
    [$migration, $granted] = $migrationFirst ? [$first, $second] : [$second, $first];

    expect($granted['error'])->toBe(UnknownRoleException::class)
        ->and($migration['error'])->toBeNull()->and($migration['effects'])->toBe(3)
        ->and(W::version())->toBe($version + 1)
        ->and(managerRaceRoles())->toBe([
            explode(':', $valid)[1].'|auditor|∞',
            explode(':', $expired)[1].'|auditor|2026-01-01 00:00:00',
            explode(':', $orphan)[1].'|auditor|∞',
        ]);
})->with(['migration first' => [true], 'grant first' => [false]])->group('engines');

it('orders revokeMany ∥ migration of one row by the panel lock; the migrated row keeps its id', function (bool $revokeFirst): void {
    $row = M::stored('role', 'inspector', 2);
    $other = M::stored('role', 'inspector', 1);
    $revoke = ['op' => 'manager-revoke', 'ids' => [$row]];
    $migrate = ['op' => 'migrate', 'from' => 'inspector', 'to' => 'auditor'];
    [$first, $second] = managerRacePair($this->processes, $revokeFirst ? $revoke : $migrate, $revokeFirst ? $migrate : $revoke);

    expect([$first['error'], $second['error']])->toBe([null, null])
        ->and(M::row($row))->toBeNull()
        ->and(M::row($other)['role'])->toBe('auditor')
        ->and($revokeFirst ? $second['effects'] : $first['effects'])->toBe($revokeFirst ? 1 : 2);
})->with(['revoke first' => [true], 'migration first' => [false]])->group('engines');
