<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;

uses()->group('batch');

it('A07 raises a duplicate restriction key from decideMany as decide does', function (): void {
    [$engine, $panel, $request] = AuthorizationWorld::compile(
        new GeneratedSource(direct: [AuthorizationWorld::grant()]),
        fn (PanelBuilder $panel) => $panel->restrictions([new RecordingRestriction, new RecordingRestriction]),
    );

    expect(fn () => $engine->decide($panel, $request))->toThrow(DefinitionException::class)
        ->and(fn () => $engine->decideMany([$request]))->toThrow(DefinitionException::class);
});
