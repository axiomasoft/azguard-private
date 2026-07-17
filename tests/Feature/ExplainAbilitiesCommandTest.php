<?php

declare(strict_types=1);

use AzGuard\Events\AccessDecision;
use AzGuard\Tests\Stubs\User;
use AzGuard\Tests\Stubs\UserWithDirectGrants;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

/**
 * F53: `guard:explain` / `guard:abilities` — off-hot-path inspection of
 * authorization decisions on top of the resolver / AccessDecision.
 *
 * §4.5 / §6 #12: explain is an opt-in re-run of Authorizer::explain(),
 * NOT a flag on the hot check() path.
 *
 * AC: `guard:explain <user> <perm>` prints the source of the verdict.
 */
describe('guard:explain', function (): void {

    it('prints the winning source when the ability is granted', function (): void {
        $user = $this->createUserWithDirectGrant('test.post.view', 'test');

        $this->artisan('guard:explain', [
            'user' => $user->getKey(),
            'ability' => 'test.post.view',
            '--model' => UserWithDirectGrants::class,
        ])
            ->expectsOutputToContain('yes')
            ->expectsOutputToContain(AccessDecision::SOURCE_GRANT)
            ->assertExitCode(0);
    });

    it('reports NO_GRANT for an ability the user lacks', function (): void {
        $user = User::factory()->create();

        $this->artisan('guard:explain', [
            'user' => $user->getKey(),
            'ability' => 'test.post.view',
        ])
            ->expectsOutputToContain('no')
            ->expectsOutputToContain(AccessDecision::NO_GRANT)
            ->assertExitCode(0);
    });

    it('emits a machine-readable JSON payload carrying the verdict source', function (): void {
        $user = $this->createUserWithDirectGrant('test.post.view', 'test');

        $code = Artisan::call('guard:explain', [
            'user' => $user->getKey(),
            'ability' => 'test.post.view',
            '--model' => UserWithDirectGrants::class,
            '--json' => true,
        ]);

        expect($code)->toBe(0);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($payload)->toBeArray()
            ->toHaveKeys(['user_id', 'panel_id', 'ability', 'allowed', 'reason_code', 'winning_source'])
            ->and($payload['allowed'])->toBeTrue()
            ->and($payload['ability'])->toBe('test.post.view')
            ->and($payload['panel_id'])->toBe('test')
            // reason_code carries the source of the verdict; winning_source is an
            // optional grant-source identity the current resolver leaves unset.
            ->and($payload['reason_code'])->toBe(AccessDecision::SOURCE_GRANT)
            ->and($payload)->toHaveKey('winning_source');
    });

    it('honours the --panel option', function (): void {
        $user = $this->createUserWithDirectGrant('test.post.view', 'test');

        $code = Artisan::call('guard:explain', [
            'user' => $user->getKey(),
            'ability' => 'test.post.view',
            '--model' => UserWithDirectGrants::class,
            '--panel' => 'test',
            '--json' => true,
        ]);

        expect($code)->toBe(0);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($payload['panel_id'])->toBe('test');
    });

    it('fails for an unknown panel', function (): void {
        $user = User::factory()->create();

        $this->artisan('guard:explain', [
            'user' => $user->getKey(),
            'ability' => 'test.post.view',
            '--panel' => 'ghost',
        ])
            ->expectsOutputToContain('not registered')
            ->assertExitCode(1);
    });

    it('fails for an unknown user', function (): void {
        $this->artisan('guard:explain', [
            'user' => '99999',
            'ability' => 'test.post.view',
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('does not dispatch AccessDecision when audit_log is off', function (): void {
        Event::fake([AccessDecision::class]);

        $user = $this->createUserWithDirectGrant('test.post.view', 'test');

        $this->artisan('guard:explain', [
            'user' => $user->getKey(),
            'ability' => 'test.post.view',
            '--model' => UserWithDirectGrants::class,
        ])->assertExitCode(0);

        Event::assertNotDispatched(AccessDecision::class);
    });
});

describe('guard:abilities', function (): void {

    it('lists the fully-resolved abilities for a user', function (): void {
        $user = $this->createUserWithDirectGrant('test.post.view', 'test');

        $this->artisan('guard:abilities', [
            'user' => $user->getKey(),
            '--model' => UserWithDirectGrants::class,
            '--panel' => 'test',
        ])
            ->expectsOutputToContain('test.post.view')
            ->assertExitCode(0);
    });

    it('emits a JSON payload with the resolved ability keys', function (): void {
        $user = $this->createUserWithDirectGrant('test.post.view', 'test');

        $code = Artisan::call('guard:abilities', [
            'user' => $user->getKey(),
            '--model' => UserWithDirectGrants::class,
            '--panel' => 'test',
            '--json' => true,
        ]);

        expect($code)->toBe(0);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($payload)->toBeArray()
            ->toHaveKeys(['user_id', 'panel_id', 'wildcard', 'abilities'])
            ->and($payload['panel_id'])->toBe('test')
            ->and($payload['wildcard'])->toBeFalse()
            ->and($payload['abilities'])->toContain('test.post.view');
    });

    it('reports no abilities for a user without grants', function (): void {
        $user = User::factory()->create();

        $code = Artisan::call('guard:abilities', [
            'user' => $user->getKey(),
            '--panel' => 'test',
            '--json' => true,
        ]);

        expect($code)->toBe(0);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($payload['abilities'])->toBe([]);
    });

    it('fails for an unknown user', function (): void {
        $this->artisan('guard:abilities', [
            'user' => '99999',
            '--panel' => 'test',
        ])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    });

    it('fails for an unknown panel', function (): void {
        $user = User::factory()->create();

        $this->artisan('guard:abilities', [
            'user' => $user->getKey(),
            '--panel' => 'ghost',
        ])
            ->expectsOutputToContain('none could be resolved')
            ->assertExitCode(1);
    });
});
