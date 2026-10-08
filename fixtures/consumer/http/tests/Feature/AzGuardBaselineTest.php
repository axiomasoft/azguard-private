<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Guards\Stored\Permissions\StoredPermission;
use App\Models\User;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\Reads;
use AzGuard\Storage\AuthorityReadBaseline;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Testing\InteractsWithAzGuard;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AzGuardBaselineTest extends TestCase
{
    use InteractsWithAzGuard;
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        // This runs before RefreshDatabase may migrate:fresh. The script forces both PHPUnit environment channels.
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(base_path(), getenv('AZGUARD_CONSUMER_FIXTURE_ROOT'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(database_path('azguard_consumer_test.sqlite'), config('database.connections.sqlite.database'));
        $this->assertSame(realpath(database_path()), realpath(dirname(config('database.connections.sqlite.database'))));
        $this->assertFileDoesNotExist(app()->getCachedConfigPath());
        $this->assertSame('bigint', config('azguard.ids.host_keys'));
        $this->assertSame('sqlite', config('azguard.storages.default.connection'));
        $this->assertSame(storage_path('framework/cache/data'), config('cache.stores.file.path'));
    }

    public function test_stored_grants_use_the_installed_schema_and_shared_cache_under_refresh_database(): void
    {
        $storage = app(StorageRegistry::class)->get('default');
        $storage->verifySchema();
        $this->assertSame('bigint', $storage->hostKeys());
        $this->assertSame(1, $storage->connection()->transactionLevel());
        $this->assertNotNull(app(AuthorityReadBaseline::class)->identity($storage));
        $user = User::factory()->create();
        Route::get('/stored/refund', static fn (): string => 'refunded')
            ->middleware(['web', 'auth', 'azguard.can:stored:orders.refund']);

        $this->actingAs($user)->get('/stored/refund')->assertForbidden();
        $this->actingAsWithPermissions($user, [StoredPermission::Refund], panel: 'stored');
        $this->assertDatabaseHas('azg_permission_grants', [
            'panel' => 'stored', 'permission' => 'orders.refund',
            'subject_id' => $user->getKey(), 'actor_type' => 'azguard.system', 'actor_reason' => 'testing',
        ]);
        $this->get('/stored/refund')->assertOk()->assertSeeText('refunded');

        app()->forgetScopedInstances();
        $grantReads = 0;
        $stateReads = 0;
        app('db')->listen(static function (QueryExecuted $query) use (&$grantReads, &$stateReads): void {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }

            if (str_contains($query->sql, 'azg_permission_grants') || str_contains($query->sql, 'azg_role_grants')) {
                $grantReads++;
            }

            if (str_contains($query->sql, 'azg_panel_state')) {
                $stateReads++;
            }
        });

        $this->assertTrue(AzGuard::check($user, StoredPermission::Refund));
        $this->assertSame(0, $grantReads, 'A fresh request scope must read grants from the file cache.');
        $this->assertGreaterThan(0, $stateReads, 'The shared-cache hit must still execute its database fence.');

        AzGuard::actingAs('testing', static fn () => AzGuard::panel('stored')->for($user)->revokePermission(StoredPermission::Refund));
        $this->assertSame(DecisionReason::NotGranted, AzGuard::panel('stored')->for($user)->decide(StoredPermission::Refund)->reason);
        $this->assertDatabaseCount('azg_permission_grants', 0);
    }

    public function test_rollback_and_restart_do_not_reuse_shared_cache_at_the_same_storage_revision(): void
    {
        $storage = app(StorageRegistry::class)->get('default');
        $connection = $storage->connection();
        $connection->rollBack();
        // Preserve one committed state so both wrapping transactions reach exactly the same incarnation/version.
        $storage->mutate('stored', static fn (): null => null);
        $this->beginDatabaseTransaction();
        $this->setUpInteractsWithAzGuard();

        try {
            $previousSession = $storage->readSession(Reads::Primary);
            $previousIdentity = $previousSession->authorityIdentity();
            $previousBaseline = app(AuthorityReadBaseline::class);
            $former = User::factory()->create();
            $this->actingAsWithPermissions($former, [StoredPermission::Refund], panel: 'stored');
            $this->assertTrue(AzGuard::check($former, StoredPermission::Refund));
            $first = $storage->state('stored');
            $connection->rollBack();

            $this->assertNull($previousBaseline->identity($storage));
            $this->assertSame(0, $storage->table('permission_grants')->count());
            $this->beginDatabaseTransaction();
            $this->setUpInteractsWithAzGuard();
            // The previous host user was rolled back too. Give the replacement a distinct subject identity.
            $replacement = User::factory()->create(['id' => $former->getKey() + 1]);
            $this->actingAsWithPermissions($replacement, [StoredPermission::Refund], panel: 'stored');
            app()->forgetScopedInstances();
            $second = $storage->state('stored');

            $this->assertNotNull($first);
            $this->assertNotNull($second);
            $this->assertSame($first->incarnation, $second->incarnation);
            $this->assertSame($first->version, $second->version);
            $this->assertNotSame($previousIdentity, $storage->readSession(Reads::Primary)->authorityIdentity());
            $this->assertTrue(AzGuard::check($replacement, StoredPermission::Refund));
            $this->assertSame(DecisionReason::NotGranted, AzGuard::panel('stored')->for($former)->decide(StoredPermission::Refund)->reason);
            $this->expectException(InvalidConfigurationException::class);
            $previousSession->assertUsable();
        } finally {
            $connection->rollBack();
            $storage->table('panel_state')->where('panel', 'stored')->delete();
            $this->beginDatabaseTransaction();
        }
    }

    public function test_an_application_transaction_above_the_test_baseline_still_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->actingAsWithPermissions($user, [StoredPermission::Refund], panel: 'stored');
        $this->assertTrue(AzGuard::check($user, StoredPermission::Refund));
        $connection = app(StorageRegistry::class)->get('default')->connection();
        $connection->beginTransaction();

        try {
            app()->forgetScopedInstances();
            $this->assertSame(DecisionReason::SourceError, AzGuard::panel('stored')->for($user)->decide(StoredPermission::Refund)->reason);
        } finally {
            $connection->rollBack();
        }
    }

    public function test_the_test_baseline_never_authorizes_production_reads(): void
    {
        $user = User::factory()->create();
        $this->actingAsWithPermissions($user, [StoredPermission::Refund], panel: 'stored');
        $this->assertTrue(AzGuard::check($user, StoredPermission::Refund));
        $storage = app(StorageRegistry::class)->get('default');
        $session = $storage->readSession(Reads::Primary);
        app()->detectEnvironment(static fn (): string => 'production');

        try {
            app()->forgetScopedInstances();
            $this->assertNull(app(AuthorityReadBaseline::class)->identity($storage));
            $this->assertSame(DecisionReason::SourceError, AzGuard::panel('stored')->for($user)->decide(StoredPermission::Refund)->reason);
            $this->expectException(InvalidConfigurationException::class);
            $session->assertUsable();
        } finally {
            app()->detectEnvironment(static fn (): string => 'testing');
        }
    }
}
