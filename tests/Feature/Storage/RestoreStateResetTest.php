<?php

declare(strict_types=1);

use AzGuard\Audit\AuditPlugin;
use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Authorization\Internal\Cache\SignedEnvelope;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Storage\Internal\StorageRegistry;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Upgrade\ReconstructedStorageSchema;
use AzGuard\Tests\Support\AuditTable;
use Composer\InstalledVersions;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

it('rehearses a reconstructed candidate schema without claiming a published baseline', function (): void {
    CrmWorld::seed();
    AuditTable::create();
    $panel = W::panel(configure: static fn (PanelBuilder $p) => $p->plugins([AuditPlugin::make()]));
    W::revoke($panel, 'seller', 1, 1);
    W::grant($panel, 'seller', 1, 1);
    CrmWorld::assign('clients.view', 2, 2, kind: 'permission', fields: ['region' => 'north', 'eligible' => true]);
    $allowed = restoreDecision();
    $denied = CrmWorld::decide($panel, client: 4)->reason;
    $storage = CrmWorld::storage();
    $tables = ['permissions', 'role_grants', 'permission_grants', 'panel_state', 'subject_revisions', 'storage_state'];
    $rows = [];
    foreach ($tables as $table) {
        $rows[$table] = $storage->table($table)->get()->map(static fn ($row) => (array) $row)->all();
    }
    $audit = $storage->table('audit_log')->get()->toArray();
    app(StorageSchema::class)->drop('default');
    app(ReconstructedStorageSchema::class)->create('default');
    $storage->table('storage_state')->delete();
    foreach ($tables as $table) {
        if ($rows[$table] !== []) {
            $storage->table($table)->insert($rows[$table]);
        }
    }

    $migrator = app('migrator');

    if (! $migrator->repositoryExists()) {
        $migrator->getRepository()->createRepository();
    }
    $migrator->getRepository()->log('2026_10_01_000000_create_azguard_storage', 1);
    $path = dirname(__DIR__, 3).'/packages/core/database/migrations';
    expect(Artisan::call('migrate', ['--path' => [$path], '--realpath' => true]))->toBe(0)
        ->and(Artisan::call('migrate', ['--path' => [$path], '--realpath' => true]))->toBe(0);
    foreach ($tables as $table) {
        expect($storage->table($table)->get()->map(static fn ($row) => (array) $row)->all())->toEqual($rows[$table]);
    }
    expect($storage->table('audit_log')->get()->toArray())->toEqual($audit)
        ->and($allowed)->toBe(DecisionReason::Granted)
        ->and(restoreDecision())->toBe($allowed)
        ->and(CrmWorld::decide($panel, client: 4)->reason)->toBe($denied)
        ->and($denied)->not->toBe(DecisionReason::Granted)
        ->and(Artisan::call('azguard:doctor'))->toBe(0);
    $filament = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/packages/filament/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($filament['require']['axiomasoft/azguard'])->toBe('self.version')
        ->and(InstalledVersions::getPrettyVersion('axiomasoft/azguard'))
        ->toBe(InstalledVersions::getPrettyVersion('axiomasoft/azguard-filament'));
});

beforeEach(function (): void {
    $this->restoreOriginalConnection = app('db')->getDefaultConnection();
    config(['database.connections.restore_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    app('db')->setDefaultConnection('restore_test');
    app()->forgetInstance(StorageRegistry::class);
});

afterEach(function (): void {
    app('db')->purge('restore_test');
    app('db')->setDefaultConnection($this->restoreOriginalConnection);
    app()->forgetInstance(StorageRegistry::class);
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('rehearses identity alias counts and derived keys without rewriting audit history', function (): void {
    CrmWorld::seed();
    AuditTable::create();
    $panel = W::panel(configure: static fn (PanelBuilder $p) => $p->plugins([AuditPlugin::make()]));
    W::revoke($panel, 'seller', 1, 1);
    W::grant($panel, 'seller', 1, 1);
    $db = CrmWorld::storage()->connection();
    $query = static fn () => $db->table('azg_role_grants')->where('subject_type', 'crm.user')
        ->where('subject_id', '1')->where('role', 'seller')->where('context_id', '1');
    $before = (array) $query()->first();
    $audit = $db->table('azg_audit_log')->orderBy('id')->get()->toArray();
    $change = static fn () => $query()->update([
        'subject_type' => 'fixture.user', 'tenant_type' => 'fixture.org',
        'tenant_key' => TenantRef::of('fixture.org', $before['tenant_id'])->key(),
        'context_type' => 'fixture.project',
        'context_key' => AssignmentScopeRef::of('fixture.project', $before['context_id'])->key(),
    ]);

    expect($query()->count())->toBe(1);
    $db->beginTransaction();
    expect($change())->toBe(1)
        ->and($db->table('azg_role_grants')->where('subject_type', 'fixture.user')->count())->toBe(1);
    $db->rollBack();
    expect((array) $query()->first())->toBe($before)
        ->and($db->table('azg_audit_log')->orderBy('id')->get()->toArray())->toEqual($audit);

    $db->beginTransaction();
    expect($change())->toBe(1);
    $row = $db->table('azg_role_grants')->where('subject_type', 'fixture.user')->first();
    expect($row->tenant_key)->toBe(TenantRef::of($row->tenant_type, $row->tenant_id)->key())
        ->and($row->context_key)->toBe(AssignmentScopeRef::of($row->context_type, $row->context_id)->key())
        ->and($query()->count())->toBe(0);
    $db->rollBack(); // The fixture is a rehearsal, not a host migration.
    expect((array) $query()->first())->toBe($before)
        ->and($db->table('azg_audit_log')->orderBy('id')->get()->toArray())->toEqual($audit);
});

it('rehearses bounded cache signing key rotation', function (): void {
    $now = new DateTimeImmutable('2026-10-06T12:00:00Z');
    config(['azguard.cache.namespace' => 'upgrade-fixture', 'azguard.cache.key_id' => 'old',
        'azguard.cache.signing_key' => str_repeat('a', 32), 'azguard.cache.previous_keys' => []]);
    $old = SignedEnvelope::fromConfig(app('config'));
    $raw = $old->encode('fixture-key', 'authority', $now, $now->modify('+60 seconds'));
    config(['azguard.cache.key_id' => 'new', 'azguard.cache.signing_key' => str_repeat('b', 32),
        'azguard.cache.previous_keys' => ['old' => ['key' => str_repeat('a', 32), 'valid_until' => '2026-10-06T12:00:30.000000Z']]]);
    $rotated = SignedEnvelope::fromConfig(app('config'));
    expect($rotated->decode($raw, 'fixture-key', $now)['payload'])->toBe('authority')
        ->and($rotated->decode($raw, 'fixture-key', $now->modify('+30 seconds')))->toBeNull();
    config(['azguard.cache.previous_keys' => []]);
    expect(SignedEnvelope::fromConfig(app('config'))->decode($raw, 'fixture-key', $now))->toBeNull();
});

function restoreDecision(): DecisionReason
{
    app()->forgetScopedInstances();
    app()->forgetInstance(Authorizer::class);

    return CrmWorld::decide(app(PanelRegistry::class)->get('crm'))->reason;
}

it('requires reset after restoring a backup before a version can collide with cached authority', function (): void {
    CrmWorld::seed();
    AuditTable::create();
    $panel = W::panel(configure: static fn (PanelBuilder $p) => $p->cache(store: 'array', ttl: 3600)->plugins([AuditPlugin::make()]));
    expect(restoreDecision())->toBe(DecisionReason::Granted);

    $db = CrmWorld::storage()->connection();
    $tables = ['role_grants', 'permission_grants', 'permissions', 'panel_state', 'subject_revisions', 'storage_state', 'audit_log'];
    $backup = [];
    foreach ($tables as $table) {
        $backup[$table] = $db->table('azg_'.$table)->get()->map(static fn ($row) => (array) $row)->all();
    }
    $before = CrmWorld::storage()->state('crm');
    $auditCount = count($backup['audit_log']);

    W::revoke($panel, 'seller', 1, 1);
    expect(restoreDecision())->toBe(DecisionReason::NotGranted);
    $cached = CrmWorld::storage()->state('crm');

    // Restore the complete authority and audit snapshot; the external cache deliberately survives.
    foreach ($tables as $table) {
        $db->table('azg_'.$table)->delete();

        if ($backup[$table] !== []) {
            $db->table('azg_'.$table)->insert($backup[$table]);
        }
    }
    expect(CrmWorld::storage()->state('crm')->incarnation)->toBe($before->incarnation)
        ->and($db->table('azg_audit_log')->count())->toBe($auditCount);

    // A different write can recreate the post-backup token while retaining this user's seller grant.
    W::revoke($panel, 'analyst', 1, 2);
    $collision = CrmWorld::storage()->state('crm');
    expect($collision->version)->toBe($cached->version)
        ->and($collision->epoch)->toBe($cached->epoch)
        ->and($collision->incarnation)->toBe($cached->incarnation)
        ->and(restoreDecision())->toBe(DecisionReason::NotGranted);

    expect(Artisan::call('azguard:state:reset', ['panel' => 'crm']))->toBe(0);
    $reset = CrmWorld::storage()->state('crm');
    expect($reset->incarnation)->not->toBe($collision->incarnation)
        ->and($reset->epoch)->toBeGreaterThan($collision->epoch)
        ->and(restoreDecision())->toBe(DecisionReason::Granted)
        ->and($db->table('azg_audit_log')->orderByDesc('id')->first())
        ->toMatchObject(['type' => 'panel.touched', 'actor_reason' => 'azguard:state:reset']);
});

it('restores a complete disposable SQLite backup before resetting the database source state', function (): void {
    $database = app('db');
    $originalDefault = $database->getDefaultConnection();
    config(['database.connections.restore_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $database->setDefaultConnection('restore_fixture');
    app()->forgetInstance(StorageRegistry::class);
    CrmWorld::seed();
    AuditTable::create();
    $dir = sys_get_temp_dir().'/azguard-restore-'.bin2hex(random_bytes(16));
    mkdir($dir, 0700);
    $live = $dir.'/authority_test.sqlite';
    $backup = $dir.'/backup_test.sqlite';
    $name = CrmWorld::storage()->connectionName();
    $reconnect = static function (string $path) use ($database, $name): void {
        $database->purge($name);
        config(["database.connections.$name.database" => $path]);
        app()->forgetInstance(StorageRegistry::class);
        app()->forgetScopedInstances();
        app()->forgetInstance(Authorizer::class);
    };

    try {
        CrmWorld::storage()->connection()->statement('VACUUM INTO ?', [$live]);
        $reconnect($live);
        $panel = W::panel(configure: static fn (PanelBuilder $p) => $p->cache(store: 'array', ttl: 3600)->plugins([AuditPlugin::make()]));
        $source = $panel->writerSource();
        $before = $source->state($panel, W::tenant());
        expect(restoreDecision())->toBe(DecisionReason::Granted);
        CrmWorld::storage()->connection()->statement('VACUUM INTO ?', [$backup]);
        $auditCount = CrmWorld::storage()->table('audit_log')->count();

        W::revoke($panel, 'seller', 1, 1);
        expect(restoreDecision())->toBe(DecisionReason::NotGranted);
        $cached = $source->state($panel, W::tenant());
        $database->purge($name);
        expect(copy($backup, $live))->toBeTrue();
        $reconnect($live);
        $panel = W::panel(configure: static fn (PanelBuilder $p) => $p->cache(store: 'array', ttl: 3600)->plugins([AuditPlugin::make()]));
        $source = $panel->writerSource();
        $restored = $source->state($panel, W::tenant());
        expect($restored->incarnation())->toBe($before->incarnation())
            ->and(CrmWorld::storage()->table('audit_log')->count())->toBe($auditCount);

        // Recreate the token of the cached denial without revoking the restored seller grant.
        W::revoke($panel, 'analyst', 1, 2);
        $collision = $source->state($panel, W::tenant());
        expect($collision->incarnation())->toBe($cached->incarnation())
            ->and($collision->epoch())->toBe($cached->epoch())
            ->and(restoreDecision())->toBe(DecisionReason::NotGranted);

        expect(Artisan::call('azguard:state:reset', ['panel' => 'crm']))->toBe(0);
        $after = $source->state($panel, W::tenant());
        expect($after->incarnation())->not->toBe($collision->incarnation())
            ->and($after->epoch())->toBeGreaterThan($collision->epoch())
            ->and(restoreDecision())->toBe(DecisionReason::Granted)
            ->and(CrmWorld::storage()->table('audit_log')->orderByDesc('id')->first())
            ->toMatchObject(['type' => 'panel.touched', 'actor_reason' => 'azguard:state:reset']);
    } finally {
        $database->purge($name);
        $database->setDefaultConnection($originalDefault);
        app()->forgetInstance(StorageRegistry::class);
        @unlink($live);
        @unlink($backup);
        @rmdir($dir);
    }
});
