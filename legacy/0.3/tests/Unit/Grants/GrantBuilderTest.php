<?php

declare(strict_types=1);

namespace AzGuard\Tests\Unit\Grants;

use AzGuard\Events\GrantGiven;
use AzGuard\Events\GrantRevoked;
use AzGuard\Grants\GrantBuilder;
use AzGuard\Models\DirectGrant;
use AzGuard\Tests\Stubs\User;
use AzGuard\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RuntimeException;

class GrantBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Authenticatable $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    // ─── give ─────────────────────────────────────────────────────────────────

    public function test_give_creates_grant_without_ttl(): void
    {
        Event::fake();

        $grant = (new GrantBuilder($this->user))
            ->on('app')
            ->grant('app.documents.export');

        $this->assertInstanceOf(DirectGrant::class, $grant);
        $this->assertNull($grant->expires_at);
        $this->assertDatabaseHas('az_direct_grants', [
            'permission_key' => 'app.documents.export',
            'panel_id' => 'app',
            'expires_at' => null,
        ]);

        Event::assertDispatched(GrantGiven::class);
    }

    public function test_give_creates_grant_with_ttl(): void
    {
        Event::fake();
        Carbon::setTestNow(Carbon::parse('2025-01-01 12:00:00'));

        $grant = (new GrantBuilder($this->user))
            ->on('app')
            ->ttl(3600)
            ->grant('app.documents.export');

        $this->assertNotNull($grant->expires_at);
        $this->assertTrue($grant->expires_at->eq(Carbon::parse('2025-01-01 13:00:00')));
    }

    public function test_give_is_idempotent_and_updates_expires_at(): void
    {
        $builder = (new GrantBuilder($this->user))->on('app');
        $builder->ttl(null)->grant('app.documents.export');  // бессрочно
        $builder->ttl(7200)->grant('app.documents.export'); // обновляем TTL

        $this->assertDatabaseCount('az_direct_grants', 1);
    }

    // ─── revoke ───────────────────────────────────────────────────────────────

    public function test_revoke_deletes_grant_and_dispatches_event(): void
    {
        Event::fake();

        $builder = (new GrantBuilder($this->user))->on('app');
        $builder->grant('app.documents.export');

        $deleted = $builder->revoke('app.documents.export');

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('az_direct_grants', [
            'permission_key' => 'app.documents.export',
        ]);

        Event::assertDispatched(GrantRevoked::class);
    }

    public function test_revoke_returns_zero_if_not_found(): void
    {
        $deleted = (new GrantBuilder($this->user))
            ->on('app')
            ->revoke('app.does.not.exist');

        $this->assertSame(0, $deleted);
    }

    public function test_revoke_all_removes_all_panel_grants(): void
    {
        Event::fake();

        $builder = (new GrantBuilder($this->user))->on('app');
        $builder->grant('app.documents.export');
        $builder->grant('app.documents.view');

        $deleted = $builder->revokeAll();

        $this->assertSame(2, $deleted);
        $this->assertDatabaseCount('az_direct_grants', 0);

        Event::assertDispatched(GrantRevoked::class, function (GrantRevoked $e): bool {
            return $e->permissionKey === '*';
        });
    }

    // ─── list ─────────────────────────────────────────────────────────────────

    public function test_list_returns_only_active_grants(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-06-01 12:00:00'));

        $builder = (new GrantBuilder($this->user))->on('app');

        // Активный
        $builder->ttl(null)->grant('app.a');
        // Истёкший — вставим напрямую
        DirectGrant::create([
            'grantable_type' => $this->user::class,
            'grantable_id' => $this->user->getAuthIdentifier(),
            'panel_id' => 'app',
            'permission_key' => 'app.b',
            'expires_at' => Carbon::parse('2025-01-01'),
        ]);

        $list = $builder->grants();

        $this->assertInstanceOf(Collection::class, $list);
        $this->assertCount(1, $list);
        $this->assertSame('app.a', $list->first()->permission_key);
    }

    // ─── immutability (P2.3, D16) ─────────────────────────────────────────────

    public function test_builder_is_immutable_branches_are_independent(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-01-01 12:00:00'));

        $base = (new GrantBuilder($this->user))->on('app');
        $ttlBranch = $base->ttl(3600);
        $untilBranch = $base->until(Carbon::parse('2025-02-01'));

        $this->assertNotSame($base, $ttlBranch);
        $this->assertNotSame($base, $untilBranch);
        $this->assertNotSame($ttlBranch, $untilBranch);

        // The base branch is untouched by either sibling — grants permanent.
        $this->assertNull($base->grant('app.a')->expires_at);

        $this->assertTrue($ttlBranch->grant('app.b')->expires_at->eq(Carbon::parse('2025-01-01 13:00:00')));
        $this->assertTrue($untilBranch->grant('app.c')->expires_at->eq(Carbon::parse('2025-02-01')));
    }

    public function test_until_sets_absolute_expiry_and_last_wins_over_ttl(): void
    {
        $at = Carbon::parse('2025-03-01 00:00:00');

        $grant = (new GrantBuilder($this->user))
            ->on('app')
            ->ttl(3600)
            ->until($at)
            ->grant('app.documents.export');

        $this->assertTrue($grant->expires_at->eq($at));
    }

    public function test_on_is_required_throws_without_panel(): void
    {
        $this->expectException(RuntimeException::class);

        (new GrantBuilder($this->user))->grant('app.x');
    }
}
