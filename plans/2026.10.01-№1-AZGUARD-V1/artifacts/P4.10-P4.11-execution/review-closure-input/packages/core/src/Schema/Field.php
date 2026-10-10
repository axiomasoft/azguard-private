<?php

declare(strict_types=1);

namespace AzGuard\Schema;

use AzGuard\Exceptions\DefinitionException;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/** An immutable description of a custom grant column or meta value. */
final class Field
{
    private ?string $label = null;

    private bool $required = false;

    private bool $multiple = false;

    private bool $inMeta = false;

    /** @var list<mixed> */
    private array $rules = [];

    private ?string $contribution = null;

    /** @param class-string|null $class */
    private function __construct(private readonly string $name, private readonly string $type, private readonly ?string $class = null)
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $name) !== 1
            || in_array($name, ['id', 'panel', 'role', 'permission', 'name', 'origin', 'expires_at', 'meta', 'created_at', 'updated_at', 'version'], true)
            || preg_match('/\A(tenant|subject|context|actor)_/', $name) === 1) {
            throw new DefinitionException('Invalid or reserved grant field name: '.$name.'.');
        }
    }

    public static function string(string $name): self
    {
        return new self($name, 'string');
    }

    public static function int(string $name): self
    {
        return new self($name, 'int');
    }

    public static function bool(string $name): self
    {
        return new self($name, 'bool');
    }

    public static function date(string $name): self
    {
        return new self($name, 'date');
    }

    /** @param class-string<BackedEnum> $enum */
    public static function enum(string $name, string $enum): self
    {
        if (! is_subclass_of($enum, BackedEnum::class)) {
            throw new DefinitionException('Field enum must be a backed enum: '.$enum.'.');
        }

        return new self($name, 'enum', $enum);
    }

    /** @param class-string<Model> $model */
    public static function model(string $name, string $model): self
    {
        if (! is_subclass_of($model, Model::class)) {
            throw new DefinitionException('Field model must be an Eloquent model: '.$model.'.');
        }

        return new self($name, 'model', $model);
    }

    public function label(string $label): self
    {
        $copy = clone $this;
        $copy->label = $label;

        return $copy;
    }

    public function required(): self
    {
        $copy = clone $this;
        $copy->required = true;

        return $copy;
    }

    public function multiple(): self
    {
        $copy = clone $this;
        $copy->multiple = true;

        return $copy;
    }

    /** @param list<mixed> $rules */
    public function rules(array $rules): self
    {
        $copy = clone $this;
        $copy->rules = [...$this->rules, ...$rules];

        return $copy;
    }

    public function inMeta(): self
    {
        $copy = clone $this;
        $copy->inMeta = true;

        return $copy;
    }

    /** @internal Assigned by the panel compiler or the model field collector. */
    public function withContribution(string $origin): self
    {
        $copy = clone $this;
        $copy->contribution = $origin;

        return $copy;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    /** @return class-string|null */
    public function valueClass(): ?string
    {
        return $this->class;
    }

    public function getLabel(): string
    {
        return $this->label ?? $this->name;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    public function isInMeta(): bool
    {
        return $this->inMeta;
    }

    /** @return list<mixed> */
    public function validationRules(): array
    {
        return $this->rules;
    }

    public function contributedBy(): ?string
    {
        return $this->contribution;
    }
}
