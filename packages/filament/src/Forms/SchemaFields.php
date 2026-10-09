<?php

declare(strict_types=1);

namespace AzGuard\Filament\Forms;

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Filament\Contracts\FilamentFormExtension;
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
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Throwable;

/**
 * The form fields of the grant fields that a panel schema declares for a role grant or a permission grant, one Filament
 * field per `FieldSchema` under the state path `fields.{name}`: a text input for `string` and `model`, a whole number
 * for `int`, a toggle for `bool`, a date for `date` and a select of the cases for `enum`; a list value is a multiple
 * select or a list of tags. A form extension that applies to the schema and the target replaces the field of a name it
 * gives a component for.
 *
 * The fields only collect values and show the rules of the schema as hints. What the values mean is checked by the
 * writer of the panel, which refuses an unknown field or a value that breaks its rules.
 *
 * @api
 */
final class SchemaFields
{
    /** The state path that holds the values. */
    public const string PATH = 'fields';

    /**
     * @param  list<FilamentFormExtension>  $extensions  in the order of registration; the first component of a name wins
     * @return list<Component>
     *
     * @throws InvalidConfigurationException when an extension gives a component for a name the schema does not declare
     *                                       for the target, or one that is not a form field of that name
     */
    public static function for(PanelSchema $schema, FieldTarget $target, array $extensions = []): array
    {
        $own = self::extended($schema, $target, $extensions);
        $components = [];

        foreach ($schema->fields($target) as $field) {
            $components[] = isset($own[$field->name])
                ? Group::make([$own[$field->name]])->statePath(self::PATH)
                : self::field($field);
        }

        return $components;
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
     * The values of the fields from the state of a form, by name, and an empty optional value as null. A name the
     * schema does not declare is kept as it came, so that the writer refuses it instead of the form dropping it.
     *
     * @param  array<mixed>|null  $state
     * @return array<string, mixed>
     */
    public static function values(PanelSchema $schema, FieldTarget $target, ?array $state): array
    {
        if (! is_array($state)) {
            return [];
        }
        $values = [];

        foreach ($state as $name => $value) {
            $values[(string) $name] = $value;
        }

        foreach ($schema->fields($target) as $field) {
            if (array_key_exists($field->name, $values)) {
                $values[$field->name] = self::value($field, $values[$field->name]);
            }
        }

        return $values;
    }

    /**
     * The values of the declared fields only, for a lookup that proposes them.
     *
     * @param  array<mixed>|null  $state
     * @return array<string, mixed>
     */
    public static function declared(PanelSchema $schema, FieldTarget $target, ?array $state): array
    {
        $names = array_map(static fn (FieldSchema $field): string => $field->name, $schema->fields($target));

        return array_intersect_key(self::values($schema, $target, $state), array_flip($names));
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

    /**
     * The components of the extensions that apply, by the name of the field.
     *
     * @param  list<FilamentFormExtension>  $extensions
     * @return array<string, Field>
     */
    private static function extended(PanelSchema $schema, FieldTarget $target, array $extensions): array
    {
        $declared = array_map(static fn (FieldSchema $field): string => $field->name, $schema->fields($target));
        $own = [];

        foreach ($extensions as $extension) {
            if (! $extension->appliesTo($schema, $target)) {
                continue;
            }

            foreach ($extension->components($schema, $target) as $name => $component) {
                $name = (string) $name;
                $where = ' of the form extension '.$extension::class.' for the '.$target->value.' fields of panel '.$schema->panel;

                if (! in_array($name, $declared, true)) {
                    throw InvalidConfigurationException::failing('filament', 'The component "'.$name.'"'.$where.' names no field of the schema.');
                }

                if (! $component instanceof Field || $component->getName() !== $name) {
                    throw InvalidConfigurationException::failing('filament', 'The component "'.$name.'"'.$where.' must be a form field named "'.$name.'".');
                }
                $own[$name] ??= $component;
            }
        }

        return $own;
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
