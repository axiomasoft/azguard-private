<?php

declare(strict_types=1);

use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldSchema;
use AzGuard\Schema\Internal\SchemaBuilder;
use AzGuard\Scopes\ScopesPlugin;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Schema\SchemaAssertions;
use AzGuard\Tests\Fixtures\Storage\Department;
use AzGuard\Tests\Modules\Scopes\Fixtures\EligibilityExternalAdapter;
use AzGuard\Tests\Modules\Scopes\Fixtures\ScopeWorld;
use AzGuard\Tests\Modules\Scopes\Fixtures\StoreScope;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

afterEach(fn () => Relation::morphMap([], false));

it('V72 describes a role binding of a scope that is not configurable, with the type as its label', function (): void {
    [, $panel] = ScopeWorld::compile(new GeneratedSource);
    $schema = (new SchemaBuilder(app(PanelRegistry::class), app()))->for($panel, TenantRef::of('org', 'A'));
    $bindings = array_merge(...array_map(static fn ($role): array => $role->toArray()['context_bindings'], $schema->roles()));

    expect($bindings)->toBe([['context_type' => 'store', 'filters' => [], 'label' => 'store', 'directory' => null, 'exact_support' => false]])
        ->and($schema->toArray()['scopes'])->toHaveCount(1)
        ->and(SchemaAssertions::nonScalars($schema->toArray()))->toBe([]);
});

it('V72 reports no exact support for an adapter-backed scope without a model', function (): void {
    [, $panel] = ScopeWorld::compile(new GeneratedSource, configure: static fn (PanelBuilder $panel) => $panel->plugins([
        ScopesPlugin::inherit(new StoreScope)->accessAdapter('store', new EligibilityExternalAdapter),
    ]));
    $schema = (new SchemaBuilder(app(PanelRegistry::class), app()))->for($panel, TenantRef::of('org', 'A'));
    $bindings = array_merge(...array_map(static fn ($role): array => $role->toArray()['context_bindings'], $schema->roles()));

    expect(array_column($bindings, 'exact_support'))->toBe([false]);
});

it('V72 gives a model field the rules that accept and refuse exactly what the grant writer does', function (mixed $value, bool $multiple): void {
    $model = new Department;
    Schema::connection($model->getConnectionName())->create($model->getTable(), static fn (Blueprint $table) => $table->id());
    $model->newQuery()->insert([['id' => 1], ['id' => 2]]);
    $field = Field::model('department_id', Department::class);
    $schema = FieldSchema::of($multiple ? $field->multiple() : $field);
    // The rules GrantFields builds for the same field: the type rule moves to each element of a list.
    $exists = Rule::exists($model->getConnectionName().'.'.$model->getTable(), $model->getKeyName());
    $writer = $multiple
        ? ['department_id' => ['nullable', 'array'], 'department_id.*' => ['required', $exists]]
        : ['department_id' => ['nullable', $exists]];

    expect(Validator::make(['department_id' => $value], ['department_id' => $schema->rules])->passes())
        ->toBe(Validator::make(['department_id' => $value], $writer)->passes());
})->with([
    'stored id' => [1, false],
    'missing id' => [9, false],
    'stored ids' => [[1, 2], true],
    'one missing id' => [[1, 9], true],
]);
