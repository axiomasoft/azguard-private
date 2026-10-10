<?php

declare(strict_types=1);

uses(AzGuard\Tests\TestCase::class);

it('does not expose a declared secret from a grant yielded before a source failure', function (): void {
    AzGuard\Tests\Fixtures\Gate\GateWorld::seed();
    $source = new AzGuard\Tests\Fixtures\Authorization\GeneratedSource(read: function (): iterable {
        yield AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld::grant(fields: ['api_token' => 'lazy-confidential-value']);
        throw new RuntimeException('Rejected value lazy-confidential-value');
    });
    [$engine, $panel, $request] = AzGuard\Tests\Fixtures\Gate\GateWorld::compile($source);
    $explanation = $engine->explain($panel, $request);
    expect($explanation->decision()->reason)->toBe(AzGuard\Kernel\Decision\DecisionReason::SourceError)
        ->and(json_encode($explanation->toArray(), JSON_THROW_ON_ERROR))->not->toContain('lazy-confidential-value');
});
