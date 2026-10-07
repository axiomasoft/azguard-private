<?php

declare(strict_types=1);

use AzGuard\Exceptions\DuplicatePermissionException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Engines\Support\CacheEngineWorld;
use AzGuard\Tests\Engines\Support\Processes;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/*
 * Races of the grants and the dynamic permission of one name on PostgreSQL/MySQL/MariaDB with independent
 * processes. Ordering comes from barriers: a worker holds the panel lock inside a pipe until released, and the
 * competitor is observed in a server lock wait before release; nothing sleeps for an interleaving.
 */

function dynamicRaceDrop(): void
{
    $schema = CrmWorld::storage()->connection()->getSchemaBuilder();
    foreach (['crm_weight', 'crm_active_build', 'crm_project_revisions', 'clients', 'project_members', 'projects', 'organization_user', 'users', 'cities', 'organizations'] as $table) {
        $schema->dropIfExists($table);
    }
    app(StorageSchema::class)->drop('default');
}

function dynamicRaceBarrier(string $name): string
{
    return sys_get_temp_dir().'/azguard-dynamic-race-'.bin2hex(random_bytes(6)).'-'.$name;
}

/**
 * Runs `$first` holding the panel lock, starts `$second`, observes it waiting for that lock, then releases.
 *
 * @param  array<string, mixed>  $first
 * @param  array<string, mixed>  $second
 * @return array{array<string, mixed>, array<string, mixed>}
 */
function dynamicRacePair(Processes $processes, array $first, array $second): array
{
    $ready = dynamicRaceBarrier('ready');
    $release = dynamicRaceBarrier('release');
    $id = dynamicRaceBarrier('id');

    try {
        $holder = $processes->start([...$first, 'dynamic' => true, 'ready' => $ready, 'release' => $release], 'change-worker.php');
        Processes::wait($ready);
        $waiter = $processes->start([...$second, 'dynamic' => true, 'id_file' => $id], 'change-worker.php');
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

/** @return list<string> */
function dynamicRaceGrants(): array
{
    return array_values(array_filter(W::keys('permission'), fn (string $key): bool => str_contains($key, '|campaigns.view|')));
}

beforeEach(function (): void {
    dynamicRaceDrop();
    CrmWorld::seed();
    W::createAction(W::dynamicPanel(), 'campaigns.view', label: 'Первое');
    $this->processes = new Processes;
});
afterEach(function (): void {
    dynamicRaceDrop();
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('C9 leaves no grant of the deleted name when a grant that passed its check commits before the delete', function (): void {
    $version = W::version();
    [$grant, $delete] = dynamicRacePair($this->processes,
        ['op' => 'grant-permission', 'name' => 'campaigns.view', 'user' => 2, 'project' => 2],
        ['op' => 'delete-permission', 'name' => 'campaigns.view']);

    expect([$grant['error'], $delete['error']])->toBe([null, null])
        ->and($grant['status'])->toBe('applied')
        ->and([$delete['status'], $delete['removed'], $delete['effects']])->toBe(['applied', 1, 2])
        ->and(dynamicRaceGrants())->toBe([])
        ->and(W::actionNames())->toBe([])
        ->and(W::version())->toBe($version + 2);
})->group('engines');

it('C9 refuses a grant of a name that a concurrent delete removed first and writes no orphan', function (): void {
    $version = W::version();
    [$delete, $grant] = dynamicRacePair($this->processes,
        ['op' => 'delete-permission', 'name' => 'campaigns.view'],
        ['op' => 'grant-permission', 'name' => 'campaigns.view', 'user' => 2, 'project' => 2]);

    expect($delete['status'])->toBe('applied')
        ->and($grant['error'])->toBe(UnknownPermissionException::class)
        ->and(dynamicRaceGrants())->toBe([])
        ->and(W::actionNames())->toBe([])
        ->and(W::version())->toBe($version + 1);
})->group('engines');

it('lets a create of the name win after a concurrent delete and starts with no grants', function (): void {
    W::pipeline()->grant(W::dynamicPanel(), W::tenant(), W::user(2), W::permission('campaigns.view'), W::project(2));
    $version = W::version();
    [$delete, $create] = dynamicRacePair($this->processes,
        ['op' => 'delete-permission', 'name' => 'campaigns.view'],
        ['op' => 'create-permission', 'name' => 'campaigns.view', 'label' => 'Второе']);

    expect([$delete['error'], $create['error']])->toBe([null, null])
        ->and([$delete['status'], $delete['removed']])->toBe(['applied', 1])
        ->and($create['status'])->toBe('applied')
        ->and(dynamicRaceGrants())->toBe([])
        ->and(array_column(W::actions(), 'label'))->toBe(['Второе'])
        ->and(W::version())->toBe($version + 2);
})->group('engines');

it('refuses a create that waited for a concurrent create of the same name, and stores one row', function (): void {
    W::deleteAction(W::dynamicPanel(), 'campaigns.view');
    [$first, $second] = dynamicRacePair($this->processes,
        ['op' => 'create-permission', 'name' => 'campaigns.view', 'label' => 'A'],
        ['op' => 'create-permission', 'name' => 'campaigns.view', 'label' => 'B']);

    expect($first['status'])->toBe('applied')
        ->and($second['error'])->toBe(DuplicatePermissionException::class)
        ->and(array_column(W::actions(), 'label'))->toBe(['A']);
})->group('engines');

it('orders update ∥ delete by the panel lock: a delete first makes the update unknown', function (bool $updateFirst): void {
    $update = ['op' => 'update-permission', 'name' => 'campaigns.view', 'label' => 'Новая'];
    $delete = ['op' => 'delete-permission', 'name' => 'campaigns.view'];
    [$first, $second] = dynamicRacePair($this->processes, $updateFirst ? $update : $delete, $updateFirst ? $delete : $update);

    expect($first['status'])->toBe('applied')
        ->and($updateFirst ? $second['status'] : $second['error'])->toBe($updateFirst ? 'applied' : UnknownPermissionException::class)
        ->and(W::actionNames())->toBe([]);
})->with(['update first' => [true], 'delete first' => [false]])->group('engines');

it('keeps tenant B grant and permission of the same name out of a concurrent delete in tenant A', function (): void {
    W::createAction(W::dynamicPanel(), 'campaigns.view', tenant: 2, label: 'B');
    W::pipeline()->grant(W::dynamicPanel(), W::tenant(2), W::user(1), W::permission('campaigns.view'), W::project(4));
    [$delete, $grant] = dynamicRacePair($this->processes,
        ['op' => 'delete-permission', 'name' => 'campaigns.view'],
        ['op' => 'grant-permission', 'name' => 'campaigns.view', 'user' => 1, 'project' => null, 'tenant' => 2]);

    expect([$delete['error'], $grant['error']])->toBe([null, null])
        ->and([$delete['status'], $delete['removed']])->toBe(['applied', 0])
        ->and($grant['status'])->toBe('applied')
        ->and(W::actionNames())->toBe(['crm.organization:2|campaigns.view'])
        ->and(W::keys('permission'))->toBe([
            'crm.organization:2|campaigns.view|1|crm.project:4|manual',
            'crm.organization:2|campaigns.view|1|global|manual',
        ]);
})->group('engines');
