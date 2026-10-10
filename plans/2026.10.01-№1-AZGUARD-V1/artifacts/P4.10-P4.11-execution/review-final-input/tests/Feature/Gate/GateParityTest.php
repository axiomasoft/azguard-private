<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelResolver;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Authorization\ScenarioGenerator;
use AzGuard\Tests\Fixtures\Gate\GateWorld;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

beforeEach(fn () => GateWorld::seed());
afterEach(function (): void {
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('P9 preserves decide batch explain and Gate parity over 200 mixed seeded scenarios', function (): void {
    foreach (ScenarioGenerator::scenarios() as $seed => $s) {
        Carbon::setTestNow($s->now);
        $policy = $seed % 3 === 0;
        $hookDenies = $seed % 7 === 0;
        $source = new GeneratedSource(direct: $s->flag() ? [$s->grant(expires: $s->expiry(($seed % 3) - 1))] : []);
        $restriction = new RecordingRestriction(deny: $s->flag());
        [$engine, $panel, $request, $resolver] = $s->world([$source], fn (PanelBuilder $p) => $p->restrictions([$restriction])->before(fn () => $hookDenies ? BeforeResult::Deny : BeforeResult::Continue));
        app()->instance(PanelResolver::class, $resolver);

        if ($policy) {
            $request = AccessRequest::for($s->subject, PermissionKey::of($s->panel, 'orders.policy'));
        }
        RuntimePolicy::$result = $s->flag() ? Response::allow('ok', 0) : Response::denyAsNotFound('hidden', 'p9');
        $decision = $engine->decide($panel, $request);
        expect($engine->decideMany([$request])->get(0))->toEqual($decision)
            ->and($engine->explain($panel, $request)->decision())->toEqual($decision);
        $gate = Gate::forUser($s->subject)->inspect($request->permission()->full(), $s->gateArguments($request));
        expect($gate->allowed())->toBe($decision->allowed(), "P9 seed=$seed")
            ->and($gate->message())->toBe($decision->message)
            ->and($gate->status())->toBe($decision->status)
            ->and($gate->code())->toBe($decision->code ?? ($decision->allowed() ? null : $decision->reason->value));
    }
})->group('properties');
