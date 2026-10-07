<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Sources\SourceManager;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;

// A dynamic catalog reads sources at request time; a source that cannot be built is a deny, never an exception.
it('A08 denies with a source error when a named source fails to build on the dynamic lookup path', function (): void {
    $broken = false;
    app(SourceManager::class)->extend('flaky', function () use (&$broken): GeneratedSource {
        if ($broken) {
            throw new RuntimeException('source factory failed');
        }

        return new GeneratedSource(name: 'flaky');
    });
    $dynamic = new class(name: 'dynamic') extends GeneratedSource
    {
        public function isDynamic(): bool
        {
            return true;
        }
    };
    [$engine, $panel, $request] = AuthorizationWorld::compile($dynamic, extra: ['flaky']);
    $unknownStatically = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.dynamic'));
    $broken = true;

    $scalar = $engine->decide($panel, $unknownStatically);
    $batch = $engine->decideMany([$unknownStatically])->get(0);

    expect($scalar->allowed())->toBeFalse()->and($scalar->reason)->toBe(DecisionReason::SourceError)
        ->and($batch->reason)->toBe($scalar->reason);
});
