<?php

declare(strict_types=1);

namespace AzGuard\Storage\Internal;

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Kernel\Internal\Narrow;
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
    public static function for(Storage|StorageReadSession $storage, FieldTarget $target, string $model, array $panelFields = [], array $decisionFields = []): self
    {
        $instance = $storage->model($target->value, $model);

        if (! $instance instanceof RoleGrant && ! $instance instanceof PermissionGrant) {
            throw new DefinitionException('Grant fields require a grant model.');
        }
        $fields = [];
        // The model class has been checked against its grant kind by Storage::model().
        foreach ([$instance::azguardFields(), $panelFields] as $position => $declared) {
            foreach (self::untrusted($declared) as $field) {
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
        foreach (self::untrusted($decisionFields) as $name) {
            if (! is_string($name) || ! isset($fields[$name])) {
                throw new DefinitionException('Decision field must name a declared grant field: '.(is_scalar($name) ? (string) $name : get_debug_type($name)).'.');
            }
        }

        foreach (array_unique($decisionFields) as $name) {
            // A decision value is compared in SQL for a list: a column holds its JSON text, which no query can compare.
            if ($fields[$name]->isMultiple() && ! $fields[$name]->isInMeta()) {
                throw new DefinitionException('Decision field '.$name.' is a list in a column: store it in meta (inMeta()) to decide on it.');
            }
        }

        return new self($fields, array_values(array_unique($decisionFields)));
    }

    /** @param array<mixed> $values  keys that name no declared field are rejected
     * @return array<string, mixed>
     */
    public function validate(array $values): array
    {
        $unknown = array_diff(array_keys($values), array_keys($this->fields));

        if ($unknown !== []) {
            throw new InvalidChangeFieldsException(array_fill_keys(array_map(strval(...), $unknown), ['Unknown grant field.']));
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

        return Narrow::map($validator->validated(), 'validated grant fields');
    }

    /** @param array<mixed> $values  keys that name no declared field are rejected
     * @return array{columns: array<string, mixed>, meta: array<string, mixed>}
     */
    public function toRow(array $values): array
    {
        $row = ['columns' => [], 'meta' => []];
        foreach ($this->validate($values) as $name => $value) {
            $field = $this->fields[$name];
            $stored = $value === null ? null : ($field->isMultiple() && is_array($value)
                ? array_map(fn (mixed $item): mixed => $this->rowValue($field, $item), self::list($field, $value))
                : $this->rowValue($field, $value));
            // A column holds a list as the JSON text of it; the JSON `meta` holds it as the list.
            $row[$field->isInMeta() ? 'meta' : 'columns'][$name] = is_array($stored) && ! $field->isInMeta() ? self::encode($stored) : $stored;
        }

        return $row;
    }

    /**
     * Every declared field in name order, as `toRow()` would store it; an absent field is null.
     *
     * @param  array{columns: array<string, mixed>, meta: array<string, mixed>}  $row
     * @return array<string, mixed>
     */
    public function canonical(array $row): array
    {
        $values = [];
        foreach ($this->fields as $name => $field) {
            $value = $field->isInMeta() ? ($row['meta'][$name] ?? null) : self::decoded($field, $row['columns'][$name] ?? null);
            $values[$name] = $value === null ? null : ($field->isMultiple() && is_array($value)
                ? array_map(fn (mixed $item): mixed => $this->storedValue($field, $item), $value)
                : $this->storedValue($field, $value));
        }
        ksort($values, SORT_STRING);

        return $values;
    }

    /**
     * The stored values of every declared field of a grant row, in the canonical form of {@see canonical()}.
     *
     * @return array<string, mixed>
     */
    public function stored(Model $model): array
    {
        $meta = $model->getAttribute('meta');
        $meta = $meta instanceof Traversable ? iterator_to_array($meta) : (is_array($meta) ? $meta : []);
        // Only named entries can be fields; a stored list carries none.
        $named = [];
        foreach ($meta as $key => $value) {
            if (is_string($key)) {
                $named[$key] = $value;
            }
        }
        $columns = [];
        foreach ($this->fields as $name => $field) {
            if (! $field->isInMeta()) {
                $columns[$name] = $model->getAttributes()[$name] ?? null;
            }
        }

        return $this->canonical(['columns' => $columns, 'meta' => $named]);
    }

    /** @return list<Field> */
    public function declared(): array
    {
        return array_values($this->fields);
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

    /**
     * The elements of a list value in order: a map is not a list, and a column cannot tell its keys.
     *
     * @param  array<mixed>  $value
     * @return list<mixed>
     */
    private static function list(Field $field, array $value): array
    {
        return array_is_list($value) ? $value : throw new InvalidChangeFieldsException([$field->name() => ['The field must be a list.']]);
    }

    /** @param array<mixed> $list a list: `list()` has refused any other */
    private static function encode(array $list): string
    {
        return json_encode($list, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The value of a column as the field declares it: the JSON text of a list column becomes the list, compared as PHP
     * values and never as the text a driver may reformat.
     */
    private static function decoded(Field $field, mixed $value): mixed
    {
        if (! $field->isMultiple() || ! is_string($value)) {
            return $value;
        }
        $list = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return is_array($list) && array_is_list($list) ? $list : throw new InvalidSourceContributionException('The column of the list field '.$field->name().' does not hold a JSON list.');
    }

    /** @param array<mixed> $values
     * @return array<mixed>
     */
    private static function untrusted(array $values): array
    {
        return $values;
    }

    /** Database drivers may return numbers and flags as strings; dates compare as UTC ISO-8601. */
    private function storedValue(Field $field, mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $field->type() === 'date' && (is_string($value) || $value instanceof DateTimeInterface) => $this->rowValue($field, $value),
            $field->type() === 'int' && is_numeric($value) => (int) $value,
            $field->type() === 'bool' && (is_bool($value) || is_int($value) || in_array($value, ['0', '1'], true)) => (bool) $value,
            $field->type() === 'model' && is_string($value) && preg_match('/\A(0|[1-9][0-9]{0,17})\z/', $value) === 1 => (int) $value,
            default => $value,
        };
    }

    private function rowValue(Field $field, mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        // Values come from validate(): a date is a date or a parsable string, an int is numeric.
        if ($field->type() === 'date') {
            $date = match (true) {
                $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
                is_string($value) => CarbonImmutable::parse($value),
                default => throw new InvalidChangeFieldsException([$field->name() => ['The field must be a date.']]),
            };

            return $date->utc()->toIso8601String();
        }

        return match ($field->type()) {
            'int' => is_numeric($value) ? (int) $value : throw new InvalidChangeFieldsException([$field->name() => ['The field must be an integer.']]),
            'bool' => (bool) $value,
            default => $value,
        };
    }
}
