<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Schema\Field;
use AzGuard\Tests\Fixtures\Storage\Department;
use AzGuard\Tests\Fixtures\Storage\Weekday;

it('creates all field types and keeps fluent modifications immutable', function (): void {
    $field = Field::string('note');
    $custom = $field->label('Note')->required()->multiple()->rules(['max:4'])->inMeta();
    expect($field->getLabel())->toBe('note')->and($field->isRequired())->toBeFalse()
        ->and($field->isMultiple())->toBeFalse()->and($field->isInMeta())->toBeFalse()->and($field->validationRules())->toBe([])
        ->and($custom->getLabel())->toBe('Note')->and($custom->isRequired())->toBeTrue()
        ->and($custom->isMultiple())->toBeTrue()->and($custom->isInMeta())->toBeTrue()->and($custom->validationRules())->toBe(['max:4']);
    expect(Field::int('floor')->type())->toBe('int')->and(Field::bool('enabled')->type())->toBe('bool')
        ->and(Field::date('starts')->type())->toBe('date')->and(Field::enum('day', Weekday::class)->valueClass())->toBe(Weekday::class)
        ->and(Field::model('department_id', Department::class)->valueClass())->toBe(Department::class);
});

it('rejects malformed and reserved field names', function (string $name): void {
    expect(fn () => Field::string($name))->toThrow(DefinitionException::class);
})->with(['', 'Note', 'bad.dot', 'bad-name', '1field', str_repeat('x', 65), 'id', 'panel', 'tenant_key', 'tenant_type', 'tenant_id',
    'subject_type', 'context_key', 'actor_reason', 'role', 'permission', 'name', 'origin', 'expires_at', 'meta', 'version', 'created_at', 'updated_at']);

it('rejects invalid enum and model classes', function (): void {
    expect(fn () => Field::enum('day', stdClass::class))->toThrow(DefinitionException::class)
        ->and(fn () => Field::model('department_id', stdClass::class))->toThrow(DefinitionException::class);
});
