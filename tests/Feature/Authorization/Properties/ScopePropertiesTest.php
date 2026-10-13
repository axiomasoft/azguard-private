<?php

declare(strict_types=1);

use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tenancy\TenantPolicy;
use AzGuard\Tests\Fixtures\Authorization\Cache\CacheSource;
use AzGuard\Tests\Fixtures\Authorization\GrantableRootRole;
use AzGuard\Tests\Fixtures\Authorization\ScenarioGenerator;
use AzGuard\Tests\Modules\Scopes\Fixtures\Membership;
use AzGuard\Tests\Modules\Scopes\Fixtures\Organization;
use AzGuard\Tests\Modules\Scopes\Fixtures\ScopeSource;
use Illuminate\Support\Carbon;

uses()->group('properties');

it('P4 without a scope declaration keeps the wide table and ignores a context it was never given', function (): void {
    // The no-declaration row of the P4 oracle, run in core: the algebra needs no module to answer it.
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        foreach ([false, true] as $role) {
            $source = new ScopeSource(direct: $role ? [] : [$s->grant($s->scope())], roles: $role ? [$s->role($s->scope(), key: 'reader')] : []);
            [$engine, $panel, $request] = $s->world([$source], mode: 'none');
            $this->assertTrue($engine->decide($panel, $request->inScope($s->scope()))->allowed(), "P4 none wide role=$role seed=$seed");
            $this->assertFalse($engine->decide($panel, $request->inScope($s->scope(true)))->allowed(), "P4 none context role=$role seed=$seed");
        }
    }
});

it('P6 typed subjects tenants and contexts with overlapping ids remain distinct in decisions', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        $grant = $s->grant($s->scope(true), source: 'identity-'.$seed);
        $source = new CacheSource(name: 'identity-'.$seed, read: function (SubjectRef $subject, array $scopes, EvaluationContext $context) use ($s, $grant): iterable {
            if ($subject->equals($s->subject) && array_filter($scopes, fn (AccessScope $scope): bool => $scope->equals($grant->scope)) !== []) {
                yield $grant;
            }
        });
        [$engine, $panel, $request] = $s->world([$source], fn (PanelBuilder $p) => $p->cache('array'), mode: 'isolated');
        $this->assertTrue($engine->decide($panel, $request->inScope($s->scope(true)))->allowed(), "P6 control seed=$seed");
        $this->assertTrue($engine->decide($panel, $request->inScope($s->scope(true)))->allowed(), "P6 warmed seed=$seed");
        expect($source->grantReads)->toBe(1);
        $otherSubject = SubjectRef::of('user', $s->subject->id().'-other');
        $this->assertFalse($engine->decide($panel, AccessRequest::for($otherSubject, $request->permission())->inScope($s->scope(true)))->allowed(), "P6 subject seed=$seed");
        foreach ([AccessScope::in($s->foreignTenant), AccessScope::in($s->tenant, $s->otherContext), $s->scope()] as $scope) {
            $this->assertFalse($engine->decide($panel, $request->inScope($scope))->allowed(), "P6 scope seed=$seed");
        }
        $this->assertTrue($engine->decide($panel, $request->inScope($s->scope(true)))->allowed(), "P6 no cache pollution seed=$seed");
        // Kind tags, aliases, leading zeroes and delimiter-bearing ids cannot collapse.
        $references = [$s->subject, $otherSubject, $s->tenant, $s->foreignTenant, $s->context, $s->otherContext,
            SubjectRef::of('user', '01'), SubjectRef::of('user', 1), SubjectRef::of('external-user', $s->subject->id()), TenantRef::global()];
        $keys = array_map(fn (object $ref): string => json_encode(IdentityCodec::encode($ref), JSON_THROW_ON_ERROR), $references);
        // The generated subject may itself be user:01; compare distinct value encodings only.
        $uniqueReferences = array_unique(array_map(fn (object $ref): string => $ref::class.':'.$ref->key(), $references));
        expect(array_unique($keys))->toHaveCount(count($uniqueReferences));
    }
});

it('P13 foreign tenant roles and global ordinary grants never authorize a selected tenant', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        foreach (['inherit', 'isolated', 'required', 'none'] as $mode) {
            $selected = $s->scope($mode !== 'none');
            $foreign = AccessScope::in($s->foreignTenant);
            $source = new ScopeSource(direct: [$s->grant($s->scope(global: true)), $s->grant($foreign)], roles: [$s->role($foreign, key: 'reader')]);
            [$engine, $panel, $request] = $s->world([$source], fn (PanelBuilder $p) => $p->tenants(TenantPolicy::required(Organization::class)
                ->requireMembership(new Membership([$s->subject->id()]))->allowGlobalRoles([GrantableRootRole::class])), mode: $mode);
            $this->assertFalse($engine->decide($panel, $request->inScope($selected))->allowed(), "P13 $mode seed=$seed");
            // Positive control uses one witness in exactly the selected tenant/context.
            $source->direct[] = $s->grant($selected);
            $this->assertTrue($engine->decide($panel, $request->inScope($selected))->allowed(), "P13 control $mode seed=$seed");
        }
    }
});
