<?php

declare(strict_types=1);

use AzGuard\Contracts\Scopes\AssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\AssignmentScopeMembership;
use AzGuard\Contracts\Scopes\AssignmentScopeResolver;
use AzGuard\Contracts\Scopes\ProvidesAccessScope;
use AzGuard\Contracts\Scopes\ProvidesAssignmentScope;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Contracts\Scopes\ResolvedAssignmentScope;
use AzGuard\Contracts\Scopes\ResourceScopeResolver;
use AzGuard\Contracts\Scopes\TenantMembership;
use AzGuard\Contracts\Scopes\TenantResolver;
use AzGuard\Contracts\Subjects\SubjectResolver;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Roles\BaseRole;
use AzGuard\Scopes\AssignmentScopePhase;
use AzGuard\Tests\Fixtures\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Scopes\Project;
use AzGuard\Tests\Fixtures\Scopes\ProjectScope;

/**
 * @return list<string> declared public methods as `name(Type $param = default): Return`
 */
function contractSignatures(string $contract): array
{
    $signatures = [];

    foreach ((new ReflectionClass($contract))->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() !== $contract) {
            continue;
        }

        $parameters = array_map(static fn (ReflectionParameter $p): string => trim(
            $p->getType().' $'.$p->getName().($p->isDefaultValueAvailable() ? ' = '.var_export($p->getDefaultValue(), true) : ''),
        ), $method->getParameters());
        $signatures[] = $method->getName().'('.implode(', ', $parameters).'): '.$method->getReturnType();
    }

    return $signatures;
}

/**
 * @param  AssignmentScopeDefinition|class-string<AssignmentScopeDefinition>  $scope
 */
function scopeDefinition(AssignmentScopeDefinition|string $scope): AssignmentScopeDefinition
{
    return is_string($scope) ? new $scope : $scope;
}

it('repeats the scope and subject contracts of the dossier', function (string $contract, array $signatures): void {
    $reflection = new ReflectionClass($contract);

    expect($reflection->isInterface())->toBeTrue()
        ->and((string) $reflection->getDocComment())->toContain('@spi')
        ->and(contractSignatures($contract))->toBe($signatures);
})->with([
    'definition' => [AssignmentScopeDefinition::class, [
        'type(): string', 'model(): ?string', 'resolve(AzGuard\Kernel\Identity\AssignmentScopeRef $ref): ?AzGuard\Contracts\Scopes\ResolvedAssignmentScope',
    ]],
    'queryable definition' => [QueryableAssignmentScopeDefinition::class, [
        'query(): Illuminate\Database\Eloquent\Builder', 'tenantOf(Illuminate\Database\Eloquent\Model $record): AzGuard\Kernel\Identity\TenantRef',
    ]],
    'resource scope resolver' => [ResourceScopeResolver::class, [
        'resolve(object $resource, ?AzGuard\Kernel\Identity\AccessScope $selected = NULL): AzGuard\Kernel\Identity\AccessScope',
    ]],
    'provides access scope' => [ProvidesAccessScope::class, ['azguardScope(): AzGuard\Kernel\Identity\AccessScope']],
    'tenant membership' => [TenantMembership::class, [
        'isMember(AzGuard\Kernel\Identity\SubjectRef $subject, AzGuard\Kernel\Identity\TenantRef $tenant): bool',
    ]],
    'tenant resolver' => [TenantResolver::class, ['resolve(Illuminate\Http\Request $request): ?AzGuard\Kernel\Identity\TenantRef']],
    'assignment scope resolver' => [AssignmentScopeResolver::class, [
        'resolve(Illuminate\Http\Request $request): ?AzGuard\Kernel\Identity\AssignmentScopeRef',
    ]],
    'assignment scope membership' => [AssignmentScopeMembership::class, [
        'isMember(AzGuard\Kernel\Identity\SubjectRef $subject, AzGuard\Kernel\Identity\AssignmentScopeRef $context): bool',
    ]],
    'provides assignment scope' => [ProvidesAssignmentScope::class, ['azguardAssignmentScope(): ?AzGuard\Kernel\Identity\AssignmentScopeRef']],
    'subject resolver' => [SubjectResolver::class, [
        'resolve(mixed $subject): AzGuard\Kernel\Identity\SubjectRef', 'model(AzGuard\Kernel\Identity\SubjectRef $ref): ?Illuminate\Database\Eloquent\Model',
    ]],
]);

it('keeps a resolved scope a plain final readonly value', function (): void {
    $ref = AssignmentScopeRef::of('crm.project', 7);
    $tenant = TenantRef::of('crm.organization', 3);
    $record = new Project;
    $resolved = new ResolvedAssignmentScope($ref, $tenant, $record);
    $reflection = new ReflectionClass(ResolvedAssignmentScope::class);

    expect($resolved->ref)->toBe($ref)
        ->and($resolved->tenant)->toBe($tenant)
        ->and($resolved->record)->toBe($record)
        ->and((new ResolvedAssignmentScope($ref, $tenant))->record)->toBeNull()
        ->and($reflection->isFinal() && $reflection->isReadOnly())->toBeTrue()
        ->and(array_map(static fn (ReflectionMethod $m): string => $m->getName(), $reflection->getMethods()))->toBe(['__construct'])
        ->and((string) $reflection->getDocComment())->toContain('@spi');
});

it('lets several code roles share one scope definition by its alias', function (BaseRole $role): void {
    $scopes = array_map(scopeDefinition(...), $role->scopes());

    expect($scopes)->toHaveCount(1)
        ->and($scopes[0])->toBeInstanceOf(QueryableAssignmentScopeDefinition::class)
        ->and($scopes[0]->type())->toBe('crm.project')
        ->and($scopes[0]->model())->toBe(Project::class)
        ->and(AssignmentScopeRef::of($scopes[0]->type(), 7)->key())->toBe('crm.project:7');
})->with([
    'instance' => fn () => new SellerRole,
    'class-string' => fn () => new AnalystRole,
]);

it('stores an alias that is a valid type alias and never a class name', function (): void {
    $type = (new ProjectScope)->type();

    IdentityCodec::assertTypeAlias($type);

    expect($type)->not->toContain('\\')
        ->and(fn () => IdentityCodec::assertTypeAlias(ProjectScope::class))->toThrow(InvalidIdentityException::class);
});

it('builds a fresh structural query without touching the database', function (): void {
    $scope = new ProjectScope;
    $first = $scope->query();

    expect($first->getModel())->toBeInstanceOf(Project::class)
        ->and($first->getQuery()->wheres)->toBe([])
        ->and($scope->query())->not->toBe($first)
        ->and($scope->tenantOf((new Project)->forceFill(['organization_id' => 3]))->key())
        ->toBe(TenantRef::of('crm.organization', 3)->key());
});

it('resolves no scope for a foreign type or the global scope', function (AssignmentScopeRef $ref): void {
    expect((new ProjectScope)->resolve($ref))->toBeNull();
})->with([
    'foreign type' => fn () => AssignmentScopeRef::of('crm.store', 7),
    'global' => fn () => AssignmentScopeRef::global(),
]);

it('names the four assignment scope phases', function (): void {
    expect(array_map(static fn (AssignmentScopePhase $p): string => $p->value, AssignmentScopePhase::cases()))
        ->toBe(['access', 'assignment', 'revocation', 'inspection'])
        ->and((string) (new ReflectionEnum(AssignmentScopePhase::class))->getDocComment())->toContain('@api');
});
