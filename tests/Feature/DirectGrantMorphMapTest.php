<?php

declare(strict_types=1);

use AzGuard\Grants\GrantBuilder;
use AzGuard\Registry\Sources\DirectGrantSource;
use AzGuard\Tests\Stubs\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * C-10 — a grant written via GrantBuilder (Eloquent path, which persists the
 * morph ALIAS when Relation::enforceMorphMap() is active) must still be found
 * by DirectGrantSource's raw query — both sides must use getMorphClass(), not
 * the raw ::class, or an aliased morph map silently orphans every grant.
 */
afterEach(function (): void {
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

it('finds a direct grant written under an enforced morph map alias', function (): void {
    Relation::enforceMorphMap(['test-user' => User::class]);

    $user = User::factory()->create();

    (new GrantBuilder($user))->on('app')->grant('app.documents.export');

    expect(DB::table('az_direct_grants')->where('permission_key', 'app.documents.export')->value('grantable_type'))
        ->toBe('test-user');

    $set = (new DirectGrantSource)->permissionsFor($user, 'app');

    expect($set->has('app.documents.export'))->toBeTrue();
});
