<?php

declare(strict_types=1);

use AzGuard\Audit\AuditPlugin;
use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\Internal\ActingActor;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Changes\AuthorizedRoleAssignments;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Models\Organization;
use AzGuard\Tests\Fixtures\Crm\Models\Project;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Events\EventWorld;
use AzGuard\Tests\Support\AuditTable;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function (): void {
    CrmWorld::seed();
    AuditTable::create();
    EventWorld::listen();
    $this->panel = W::panel(configure: static fn (PanelBuilder $panel) => $panel->plugins([AuditPlugin::make()]));
    $this->service = new AuthorizedRoleAssignments;
});

afterEach(function (): void {
    CrmWorld::resetRuntime();
    EventWorld::reset();
});

function assignmentSnapshot(): array
{
    return [W::rows(), W::version(), CrmWorld::storage()->table('subject_revisions')->orderBy('id')->get()->toArray(),
        EventWorld::auditRows(), EventWorld::events()];
}

it('authorizes the actor, target and exact project before both grant and revoke', function (): void {
    $before = app(ActingActor::class)->current($this->panel);
    $grant = $this->service->change(3, 2, 1, 1, 'analyst', true);
    $revoke = $this->service->change(3, 2, 1, 1, 'analyst', false);

    expect($grant->applied())->toBeTrue()->and($revoke->applied())->toBeTrue()
        ->and(array_column(EventWorld::auditRows(), 'type'))->toBe(['role.granted', 'role.revoked'])
        ->and(array_column(EventWorld::auditRows(), 'actor_id'))->toBe(['3', '3'])
        ->and(array_column(EventWorld::auditRows(), 'subject_id'))->toBe(['2', '2'])
        ->and(app(ActingActor::class)->current($this->panel))->toEqual($before);
});

it('denies invalid actors, targets, roles and scopes without effects', function (?int $actor, int $target, int $tenant, int $project, string $role): void {
    foreach ([true, false] as $grant) {
        $before = assignmentSnapshot();
        expect(fn () => $this->service->change($actor, $target, $tenant, $project, $role, $grant))
            ->toThrow(AuthorizationException::class)
            ->and(assignmentSnapshot())->toEqual($before);
    }
})->with([
    'missing actor' => [null, 2, 1, 1, 'analyst'],
    'unauthorized actor' => [2, 1, 1, 1, 'analyst'],
    'self escalation' => [3, 3, 1, 1, 'analyst'],
    'superadmin role' => [3, 2, 1, 1, 'root'],
    'cross tenant target' => [3, 4, 1, 1, 'analyst'],
    'foreign project' => [3, 2, 1, 4, 'analyst'],
    'unmanaged project' => [3, 2, 1, 2, 'analyst'],
    'inactive project' => [3, 2, 1, 3, 'analyst'],
]);

it('does not let forged actor or system fields influence an untrusted request', function (): void {
    // An HTTP controller obtains $sessionActor from its guard and rejects fields outside this allowlist.
    $request = function (?int $sessionActor, array $input): ChangeResult {
        if (array_diff(array_keys($input), ['target', 'tenant', 'project', 'role', 'grant']) !== []) {
            throw new AuthorizationException('Unexpected request field.');
        }

        return $this->service->change($sessionActor, $input['target'], $input['tenant'], $input['project'], $input['role'], $input['grant']);
    };
    $input = ['target' => 2, 'tenant' => 1, 'project' => 1, 'role' => 'analyst', 'grant' => true];
    foreach ([null, 2, 3] as $sessionActor) {
        foreach ([['actor' => 3], ['system_reason' => 'console'], ['actor_id' => 3]] as $forgery) {
            $before = assignmentSnapshot();
            expect(fn () => $request($sessionActor, $input + $forgery))->toThrow(AuthorizationException::class)
                ->and(assignmentSnapshot())->toEqual($before);
        }
    }
    $before = assignmentSnapshot();
    expect(fn () => $request(null, $input))->toThrow(AuthorizationException::class)
        ->and(assignmentSnapshot())->toEqual($before);
    expect($request(3, $input)->applied())->toBeTrue();
});

it('rechecks a user job at execution after the actor loses authority', function (): void {
    $job = fn () => $this->service->change(3, 2, 1, 1, 'analyst', true);
    W::revoke($this->panel, 'tenant-admin', 3, 1);
    $before = assignmentSnapshot();

    expect($job)->toThrow(AuthorizationException::class)->and(assignmentSnapshot())->toEqual($before);
});

it('refuses assignments after primary host state changes while the read PDO is stale', function (string $change): void {
    $connection = User::query()->getConnection();

    if ($connection->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This split-PDO regression uses an isolated SQLite snapshot.');
    }
    $primary = $connection->getPdo();
    $replica = new PDO('sqlite::memory:');
    $replica->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ($primary->query("select name, sql from sqlite_master where type = 'table' and name not like 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC) as $table) {
        $replica->exec($table['sql']);
        $name = '"'.str_replace('"', '""', $table['name']).'"';
        foreach ($primary->query('select * from '.$name)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $insert = $replica->prepare('insert into '.$name.' values ('.implode(',', array_fill(0, count($row), '?')).')');
            $insert->execute(array_values($row));
        }
    }

    if ($change === 'project') {
        Project::query()->whereKey(1)->update(['is_active' => false]);
    } elseif ($change === 'authority') {
        W::revoke($this->panel, 'tenant-admin', 3, 1);
    } else {
        $connection->table('organization_user')->where('organization_id', 1)->where('user_id', $change === 'actor membership' ? 3 : 2)->delete();
    }
    $before = assignmentSnapshot();
    $previous = $connection->getRawReadPdo();
    $connection->setReadPdo($replica);

    try {
        expect($connection->getReadPdo())->toBe($replica);
        expect(fn () => $this->service->change(3, 2, 1, 1, 'analyst', true))->toThrow(AuthorizationException::class);
    } finally {
        $connection->setReadPdo($previous);
    }
    expect(assignmentSnapshot())->toEqual($before);
})->with(['actor membership', 'target membership', 'project', 'authority'])->group('server-skip');

it('allows an explicitly trusted maintenance job to use a server-owned system reason', function (): void {
    $result = AzGuard::actingAs('maintenance: backfill', fn () => AzGuard::panel('crm')
        ->inTenant(Organization::query()->findOrFail(1))->for(User::query()->findOrFail(2))
        ->grantRole('analyst', on: Project::query()->findOrFail(1)));

    expect($result->applied())->toBeTrue()->and(EventWorld::auditRows()[0]['actor_type'])->toBe(ActorRef::SYSTEM_TYPE)
        ->and(EventWorld::auditRows()[0]['actor_reason'])->toBe('maintenance: backfill');
});

it('restores the actor frame when a write fails', function (): void {
    $panel = W::panel([static function (Change $change, Closure $next): ChangeResult {
        throw new RuntimeException('write failed');
    }], static fn (PanelBuilder $builder) => $builder->plugins([AuditPlugin::make()]));
    $actors = app(ActingActor::class);
    $outer = ActorRef::system('outer');
    $actors->run($outer, function () use ($actors, $panel, $outer): void {
        $before = assignmentSnapshot();
        expect(fn () => $this->service->change(3, 2, 1, 1, 'analyst', true))->toThrow(RuntimeException::class, 'write failed')
            ->and($actors->current($panel))->toEqual($outer)->and(assignmentSnapshot())->toEqual($before);
    });
});
