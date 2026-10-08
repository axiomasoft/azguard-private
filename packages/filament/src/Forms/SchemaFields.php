<?php

declare(strict_types=1);

namespace AzGuard\Filament\Forms;

use AzGuard\Schema\FieldSchema;
use AzGuard\Schema\FieldTarget;
use AzGuard\Schema\PanelSchema;
use DateTimeImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Throwable;

/**
 * The form fields of the grant fields that a panel schema declares for a role grant or a permission grant, one Filament
 * field per `FieldSchema` under the state path `fields.{name}`: a text input for `string` and `model`, a whole number
 * for `int`, a toggle for `bool`, a date for `date` and a select of the cases for `enum`; a list value is a multiple
 * select or a list of tags.
 *
 * The fields only collect values. What the values mean is checked by the writer of the panel, which refuses an unknown
 * field or a value that breaks its rules, and the form sends nothing else than the declared names.
 *
 * @api
 */
final class SchemaFields
{
    /** The state path that holds the values. */
    public const string PATH = 'fields';

    /**
     * @return list<Field>
     */
    public static function for(PanelSchema $schema, FieldTarget $target): array
    {
        return array_map(self::field(...), $schema->fields($target));
    }

    public static function field(FieldSchema $field): Field
    {
        $multiple = in_array('array', $field->rules, true);
        $name = self::PATH.'.'.$field->name;

        $component = match (true) {
            $field->type === 'enum' => Select::make($name)->options($field->options ?? [])->multiple($multiple),
            $multiple => TagsInput::make($name),
            $field->type === 'bool' => Toggle::make($name),
            $field->type === 'int' => TextInput::make($name)->integer(),
            $field->type === 'date' => DatePicker::make($name),
            default => TextInput::make($name),
        };

        return $component->label($field->label)->required(in_array('required', $field->rules, true));
    }

    /**
     * The values of the declared fields from the state of a form, by name; a name the schema does not declare is left
     * out, and an empty optional value is null.
     *
     * @param  array<mixed>|null  $state
     * @return array<string, mixed>
     */
    public static function values(PanelSchema $schema, FieldTarget $target, ?array $state): array
    {
        $values = [];

        foreach ($schema->fields($target) as $field) {
            if (! is_array($state) || ! array_key_exists($field->name, $state)) {
                continue;
            }
            $values[$field->name] = self::value($field, $state[$field->name]);
        }

        return $values;
    }

    /**
     * The state of the form for stored values: dates as `Y-m-d`.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function state(PanelSchema $schema, FieldTarget $target, array $values): array
    {
        $state = [];

        foreach ($schema->fields($target) as $field) {
            $value = $values[$field->name] ?? null;
            $state[$field->name] = $field->type === 'date' && is_string($value) ? substr($value, 0, 10) : $value;
        }

        return $state;
    }

    private static function value(FieldSchema $field, mixed $value): mixed
    {
        if ($value === '' || $value === []) {
            return $field->type === 'bool' ? false : null;
        }

        return match ($field->type) {
            'int' => is_numeric($value) && (string) (int) $value === trim((string) $value) ? (int) $value : $value,
            'date' => is_string($value) ? self::date($value) : $value,
            default => $value,
        };
    }

    private static function date(string $value): string
    {
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d');
        } catch (Throwable) {
            return $value;
        }
    }
}
