<?php

declare(strict_types=1);

use AzGuard\Attributes\AsSource;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\DescribesSchema;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Contracts\Sources\StoresGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\WriterConflictException;

it('keeps StoresGrants to the transaction until apply arrives', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(StoresGrants::class))->getMethods(),
    );

    expect($methods)->toEqualCanonicalizing(['id', 'transaction'])
        ->and($methods)->not->toContain('apply')
        ->and((new ReflectionClass(StoresGrants::class))->getDocComment())->toContain('@spi');
});

it('declares the evaluation context the dossier names and nothing else', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(EvaluationContext::class))->getMethods(),
    );

    expect($methods)->toBe([
        'panel', 'scope', 'scopes', 'resource', 'state', 'now', 'subjectModel', 'actor', 'actorModel', 'role', 'grant', 'matchingGrants',
    ])->and((new ReflectionClass(EvaluationContext::class))->getDocComment())->toContain('@api');
});

it('names the three volatilities and tags every new source contract', function (string $class): void {
    expect((new ReflectionClass($class))->getDocComment())->toContain('@spi');
})->with([
    ProvidesGrants::class,
    ProvidesRoleGrants::class,
    DescribesSchema::class,
    SourceDescription::class,
    Volatility::class,
]);

it('lists stable, request and volatile', function (): void {
    expect(array_map(static fn (Volatility $case): string => $case->value, Volatility::cases()))
        ->toBe(['stable', 'request', 'volatile']);
});

it('carries the source name on the attribute', function (): void {
    $attribute = (new ReflectionClass(AsSource::class))->getAttributes()[0] ?? null;

    expect($attribute)->not->toBeNull()
        ->and((new AsSource('ldap'))->name)->toBe('ldap')
        ->and((new ReflectionClass(AsSource::class))->getDocComment())->toContain('@api');
});

it('codes a writer conflict as a definition error', function (): void {
    $exception = new WriterConflictException('two writers');

    expect($exception)->toBeInstanceOf(DefinitionException::class)
        ->and($exception->code())->toBe('writer_conflict');
});
