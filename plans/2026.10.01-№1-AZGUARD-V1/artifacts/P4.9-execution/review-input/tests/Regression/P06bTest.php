<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Scopes\ScopeWorld;
use Illuminate\Database\Eloquent\Relations\Relation;

afterEach(fn () => Relation::morphMap([], false));

it('P06b consistently rejects an unsupported explicit context instead of global allow', function (): void {
    [$engine, $panel, $request] = ScopeWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]), 'none', tenant: false);
    expect($engine->decide($panel, $request)->allowed())->toBeTrue();
    $context = AssignmentScopeRef::of('workspace', 42);

    foreach ([$request->on($context), $request->inScope(AccessScope::in(TenantRef::global(), $context)), $request->on($context)->traced()] as $explicit) {
        $decision = $engine->decide($panel, $explicit);
        expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::AssignmentScopeNotAccepted);
    }
});
