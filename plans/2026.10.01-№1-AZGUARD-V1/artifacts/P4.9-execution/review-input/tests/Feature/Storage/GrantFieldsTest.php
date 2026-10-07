<?php

declare(strict_types=1);

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;
use AzGuard\Storage\GrantFields;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Storage\StorageMutation;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Storage\AdminRoleGrant;
use AzGuard\Tests\Fixtures\Storage\SchemaAssertions;
use AzGuard\Tests\Fixtures\Storage\Weekday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;

beforeEach(function (): void {
    $this->storage = app(StorageRegistry::class)->get('default');
    app(StorageSchema::class)->drop('default');
    app(StorageSchema::class)->create('default');
    $schema = $this->storage->connection()->getSchemaBuilder();
    $schema->dropIfExists('departments');
    $schema->create('departments', function (Blueprint $table): void {
        $table->id();
    });
    $this->storage->connection()->table('departments')->insert(['id' => 7]);
    $schema->table($this->storage->prefix().'role_grants', function (Blueprint $table): void {
        $table->integer('department_id')->nullable();
    });
});

afterEach(function (): void {
    app(StorageSchema::class)->drop('default');
    $this->storage->connection()->getSchemaBuilder()->dropIfExists('departments');
});

it('validates custom fields writes columns and meta through mutate and restricts decision values', function (): void {
    $fields = GrantFields::for($this->storage, FieldTarget::RoleGrant, AdminRoleGrant::class, decisionFields: ['weekdays']);
    $row = $fields->toRow($fields->validate(['department_id' => 7, 'weekdays' => [Weekday::Monday, 5]]));
    expect($row)->toBe(['columns' => ['department_id' => 7], 'meta' => ['weekdays' => [1, 5]]]);
    $this->storage->mutate('admin', function (StorageMutation $mutation) use ($row): void {
        $mutation->table('role_grants')->insert(SchemaAssertions::grant() + $row['columns'] + ['meta' => json_encode($row['meta'])]);
        $mutation->touch('admin');
    });
    $model = $this->storage->model('role_grant', AdminRoleGrant::class)->newQuery()->firstOrFail();
    expect($model->department_id)->toBe(7)->and($fields->decisionValues($model))->toBe(['weekdays' => [1, 5]])
        ->and($this->storage->state('admin')->version)->toBe(1);
});

it('returns field errors for unknown missing invalid enum and nonexistent model values', function (array $values, string $error): void {
    $fields = GrantFields::for($this->storage, FieldTarget::RoleGrant, AdminRoleGrant::class);

    try {
        $fields->validate($values);
        test()->fail('Expected InvalidChangeFieldsException');
    } catch (InvalidChangeFieldsException $exception) {
        expect($exception->code())->toBe('invalid_change_fields')->and($exception->errors())->toHaveKey($error);
    }
})->with([
    'unknown' => [['department_id' => 7, 'floor' => 2], 'floor'],
    'missing required' => [[], 'department_id'],
    'invalid enum' => [['department_id' => 7, 'weekdays' => [9]], 'weekdays.0'],
    'not array' => [['department_id' => 7, 'weekdays' => 1], 'weekdays'],
    'missing model' => [['department_id' => 8], 'department_id'],
]);

it('normalizes scalar enum and date values and applies custom rules', function (): void {
    $fields = GrantFields::for($this->storage, FieldTarget::PermissionGrant, PermissionGrant::class, [
        Field::string('note')->rules(['max:4'])->inMeta(), Field::int('floor')->inMeta(), Field::bool('enabled')->inMeta(),
        Field::date('starts')->inMeta(), Field::enum('day', Weekday::class)->inMeta(),
    ]);
    expect($fields->toRow(['note' => 'yes', 'floor' => '2', 'enabled' => '1', 'starts' => CarbonImmutable::parse('2026-10-01T12:00:00+03:00'), 'day' => Weekday::Friday]))
        ->toBe(['columns' => [], 'meta' => ['note' => 'yes', 'floor' => 2, 'enabled' => true, 'starts' => '2026-10-01T09:00:00+00:00', 'day' => 5]])
        ->and(fn () => $fields->validate(['note' => 'longer']))->toThrow(InvalidChangeFieldsException::class)
        ->and(fn () => $fields->toRow(['unknown' => 1]))->toThrow(InvalidChangeFieldsException::class);
});

it('rejects duplicate model and panel fields and undeclared decision fields', function (): void {
    expect(fn () => GrantFields::for($this->storage, FieldTarget::RoleGrant, AdminRoleGrant::class, [Field::string('weekdays')->withContribution('plugin:notes')]))
        ->toThrow(DefinitionException::class, 'and plugin:notes')
        ->and(fn () => GrantFields::for($this->storage, FieldTarget::RoleGrant, AdminRoleGrant::class, decisionFields: ['floor']))->toThrow(DefinitionException::class);
});

it('parses default models without coupling configuration to storage inheritance', function (): void {
    config()->set('azguard.defaults.models.role_grant', AdminRoleGrant::class);
    expect(AzGuardConfig::fromRepository(config())->defaultModels()['role_grant'])->toBe(AdminRoleGrant::class);
    config()->set('azguard.defaults.models.role_grant', 'MissingModel');
    expect(fn () => AzGuardConfig::fromRepository(config()))->toThrow(InvalidConfigurationException::class);
});
