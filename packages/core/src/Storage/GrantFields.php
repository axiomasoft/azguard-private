<?php

declare(strict_types=1);

namespace AzGuard\Storage;

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Traversable;

/** Collects and validates the fields of one model in one panel. */
final readonly class GrantFields
{
    /**
     * @param  array<string, Field>  $fields
     * @param  list<string>  $decisionFields
     */
    private function __construct(private array $fields, private array $decisionFields) {}

    /**
     * @param  class-string<Model>  $model
     * @param  list<Field>  $panelFields
     * @param  list<string>  $decisionFields
     */
    public static function for(Storage $storage, FieldTarget $target, string $model, array $panelFields = [], array $decisionFields = []): self
    {
        $instance = $storage->model($target->value, $model);

        if (! $instance instanceof RoleGrant && ! $instance instanceof PermissionGrant) {
            throw new DefinitionException('Grant fields require a grant model.');
        }
        $fields = [];
        // The model class has been checked against its grant kind by Storage::model().
        foreach ([$instance::azguardFields(), $panelFields] as $position => $declared) {
            foreach (self::declared($declared) as $field) {
                if (! $field instanceof Field) {
                    throw new DefinitionException('Grant fields must be Field objects on '.$model.'.');
                }
                $origin = $position === 0 ? 'model:'.$model : ($field->contributedBy() ?? 'panel');
                $name = $field->name();

                if (isset($fields[$name])) {
                    throw new DefinitionException('Duplicate grant field '.$name.' from '.$fields[$name]->contributedBy().' and '.$origin.'.');
                }
                $fields[$name] = $field->withContribution($origin);
            }
        }
        foreach (self::declared($decisionFields) as $name) {
            if (! is_string($name) || ! isset($fields[$name])) {
                throw new DefinitionException('Decision field must name a declared grant field: '.(is_scalar($name) ? (string) $name : get_debug_type($name)).'.');
            }
        }

        return new self($fields, array_values(array_unique($decisionFields)));
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function validate(array $values): array
    {
        $unknown = array_diff(array_keys($values), array_keys($this->fields));

        if ($unknown !== []) {
            throw new InvalidChangeFieldsException(array_fill_keys($unknown, ['Unknown grant field.']));
        }
        $rules = [];
        foreach ($this->fields as $name => $field) {
            $presence = $field->isRequired() ? 'required' : 'nullable';
            $type = $this->typeRule($field);
            $rules[$name] = [$presence, $field->isMultiple() ? 'array' : $type, ...$field->validationRules()];

            if ($field->isMultiple()) {
                $rules[$name.'.*'] = ['required', $type];
            }
        }
        $validator = app(Factory::class)->make($values, $rules);

        if ($validator->fails()) {
            throw new InvalidChangeFieldsException(array_map(array_values(...), $validator->errors()->messages()));
        }

        return $validator->validated();
    }

    /** @param array<string, mixed> $values
     * @return array{columns: array<string, mixed>, meta: array<string, mixed>}
     */
    public function toRow(array $values): array
    {
        $row = ['columns' => [], 'meta' => []];
        foreach ($this->validate($values) as $name => $value) {
            $field = $this->fields[$name];
            $row[$field->isInMeta() ? 'meta' : 'columns'][$name] = $value === null ? null : ($field->isMultiple()
                ? array_map(fn (mixed $item): mixed => $this->rowValue($field, $item), $value)
                : $this->rowValue($field, $value));
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public function decisionValues(Model $model): array
    {
        $meta = $model->getAttribute('meta');
        $meta = $meta instanceof Traversable ? iterator_to_array($meta) : (is_array($meta) ? $meta : []);
        $values = [];
        foreach ($this->decisionFields as $name) {
            $values[$name] = $this->fields[$name]->isInMeta() ? ($meta[$name] ?? null) : $model->getAttribute($name);
        }

        return $values;
    }

    private function typeRule(Field $field): mixed
    {
        if ($field->type() === 'enum') {
            return Rule::enum($field->valueClass() ?? throw new DefinitionException('Enum field requires an enum class.'));
        }

        if ($field->type() === 'model') {
            $class = $field->valueClass() ?? throw new DefinitionException('Model field requires a model class.');
            $model = new $class;

            if (! $model instanceof Model) {
                throw new DefinitionException('Model field requires an Eloquent model.');
            }
            $table = $model->getTable();
            $connection = $model->getConnectionName();

            return Rule::exists($connection === null ? $table : $connection.'.'.$table, $model->getKeyName());
        }

        return match ($field->type()) {
            'int' => 'integer',
            'bool' => 'boolean',
            default => $field->type(),
        };
    }

    /** @param array<mixed> $values
     * @return array<mixed>
     */
    private static function declared(array $values): array
    {
        return $values;
    }

    private function rowValue(Field $field, mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($field->type() === 'date') {
            return ($value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value))
                ->utc()->toIso8601String();
        }

        return match ($field->type()) {
            'int' => (int) $value,
            'bool' => (bool) $value,
            default => $value,
        };
    }
}
