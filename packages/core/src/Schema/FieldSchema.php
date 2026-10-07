<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use Stringable;

/**
 * A grant field as an interface shows it: scalars and class names only, never a rule object or a closure.
 */
final readonly class FieldSchema implements JsonSerializable
{
    /**
     * @param  string  $type  `string`, `int`, `bool`, `date`, `enum` or `model`
     * @param  list<string>  $rules  the presence rule, `array` for a list value, `exists` for a model and the declared rules
     * @param  array<int|string, string>|null  $options  value => label of the cases of an enum field
     * @param  string  $contributedBy  `model:Class`, `provider`, `plugin:id` or `configure`
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type,
        public array $rules,
        public ?array $options,
        public bool $inMeta,
        public string $contributedBy,
    ) {}

    /**
     * The description of a declared field; a rule object becomes its string form or its class.
     */
    public static function of(Field $field): self
    {
        $rules = [$field->isRequired() ? 'required' : 'nullable'];

        if ($field->isMultiple()) {
            $rules[] = 'array';
        }
        $class = $field->valueClass();

        if ($field->type() === 'model' && $class !== null && is_subclass_of($class, Model::class)) {
            $rules[] = 'exists:'.$class.','.(new $class)->getKeyName();
        }

        foreach ($field->validationRules() as $rule) {
            $rules[] = self::rule($rule);
        }
        $options = null;

        if ($field->type() === 'enum' && $class !== null && is_subclass_of($class, BackedEnum::class)) {
            $options = [];

            foreach ($class::cases() as $case) {
                $options[$case->value] = $case->name;
            }
        }

        return new self(
            name: $field->name(),
            label: $field->getLabel(),
            type: $field->type(),
            rules: $rules,
            options: $options,
            inMeta: $field->isInMeta(),
            contributedBy: $field->contributedBy() ?? 'panel',
        );
    }

    private static function rule(mixed $rule): string
    {
        return match (true) {
            is_string($rule) => $rule,
            is_int($rule), is_float($rule) => (string) $rule,
            is_bool($rule) => $rule ? 'true' : 'false',
            $rule instanceof Closure => Closure::class,
            $rule instanceof Stringable => (string) $rule,
            is_object($rule) => $rule::class,
            default => get_debug_type($rule),
        };
    }

    /**
     * @return array{name: string, label: string, type: string, rules: list<string>, options: array<int|string, string>|null, in_meta: bool, contributed_by: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'type' => $this->type,
            'rules' => $this->rules,
            'options' => $this->options,
            'in_meta' => $this->inMeta,
            'contributed_by' => $this->contributedBy,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
