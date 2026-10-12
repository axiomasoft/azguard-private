<?php

declare(strict_types=1);

use AzGuard\Attributes\AsSource;
use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\DescribesSchema;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\WriterConflictException;
use AzGuard\Panels\Panel;
use AzGuard\Schema\WriterSchema;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Arch\ApiUsage;

it('keeps apply and transaction on the database source only: the writer is not an SPI (D10)', function (): void {
    $apply = new ReflectionMethod(DatabaseSource::class, 'apply');

    expect(interface_exists('AzGuard\\Contracts\\Sources\\StoresGrants'))->toBeFalse()
        ->and((string) $apply->getParameters()[0]->getType())->toBe(Change::class)
        ->and((string) $apply->getReturnType())->toBe(ChangeResult::class)
        ->and(method_exists(DatabaseSource::class, 'transaction'))->toBeTrue()
        ->and(ApiUsage::publication(DatabaseSource::class))->not->toBeNull()
        ->and((string) (new ReflectionMethod(Panel::class, 'writer'))->getReturnType())->toBe('?'.WriterSchema::class);
});

it('declares the documented evaluation context and nothing else', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(EvaluationContext::class))->getMethods(),
    );

    expect($methods)->toBe([
        'panel', 'scope', 'scopes', 'resource', 'state', 'now', 'subjectModel', 'actor', 'actorModel', 'role', 'grant', 'matchingGrants',
    ])->and(ApiUsage::publication(EvaluationContext::class))->toBe('spi-call');
});

it('names the three volatilities and publishes every new source contract', function (string $class, string $publication): void {
    expect(ApiUsage::publication($class))->toBe($publication);
})->with([
    [ProvidesGrants::class, 'spi-implement'],
    [ProvidesRoleGrants::class, 'spi-implement'],
    [DescribesSchema::class, 'spi-implement'],
    [SourceDescription::class, 'api'],
    [Volatility::class, 'spi-call'],
]);

it('lists stable, request and volatile', function (): void {
    expect(array_map(static fn (Volatility $case): string => $case->value, Volatility::cases()))
        ->toBe(['stable', 'request', 'volatile']);
});

it('carries the source name on the attribute', function (): void {
    $attribute = (new ReflectionClass(AsSource::class))->getAttributes()[0] ?? null;

    expect($attribute)->not->toBeNull()
        ->and((new AsSource('ldap'))->name)->toBe('ldap')
        ->and(ApiUsage::publication(AsSource::class))->toBe('api');
});

it('codes a writer conflict as a definition error', function (): void {
    $exception = new WriterConflictException('two writers');

    expect($exception)->toBeInstanceOf(DefinitionException::class)
        ->and($exception->code())->toBe('writer_conflict');
});
