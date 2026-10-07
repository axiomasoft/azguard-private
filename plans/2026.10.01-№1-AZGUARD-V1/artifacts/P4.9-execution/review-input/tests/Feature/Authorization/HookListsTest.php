<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;

it('rejects invalid hook list elements with their origin at compilation', function (string $setting): void {
    expect(fn () => AuthorizationWorld::compile(new GeneratedSource, fn (PanelBuilder $panel) => $panel->{$setting}([new stdClass])))->toThrow(DefinitionException::class, 'provider');
})->with(['before', 'after', 'restrictions', 'grantConditions']);
it('rejects duplicate restriction keys as direct API configuration errors', function (): void {
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]), fn (PanelBuilder $panel) => $panel->restrictions([new RecordingRestriction, new RecordingRestriction]));
    expect(fn () => $engine->decide($panel, $request))->toThrow(DefinitionException::class, 'duplicate restriction key');
});
it('rejects duplicate restriction keys even when another stage denies', function (string $denial): void {
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource, function (PanelBuilder $panel) use ($denial): void {
        $panel->restrictions([new RecordingRestriction, new RecordingRestriction]);

        if ($denial === 'before') {
            $panel->before(fn (): BeforeResult => BeforeResult::Deny);
        }
    });

    if ($denial === 'boundary') {
        $request = AccessRequest::for($request->subject(), $request->permission())->inTenant(TenantRef::of('org', 2));
    }
    expect(fn () => $engine->decide($panel, $request))->toThrow(DefinitionException::class, 'duplicate restriction key');
})->with(['not_granted', 'before', 'boundary']);
it('resolves class string components on each operation rather than compilation', function (): void {
    $resolutions = 0;
    app()->bind(RecordingRestriction::class, function () use (&$resolutions): RecordingRestriction {
        $resolutions++;

        return new RecordingRestriction;
    });
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]), fn (PanelBuilder $panel) => $panel->restrictions([RecordingRestriction::class]));
    expect($resolutions)->toBe(0);
    $engine->decide($panel, $request);
    $engine->decide($panel, $request);
    expect($resolutions)->toBe(2);
});
