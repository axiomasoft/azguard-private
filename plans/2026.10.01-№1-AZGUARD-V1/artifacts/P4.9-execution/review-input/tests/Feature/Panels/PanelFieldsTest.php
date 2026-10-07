<?php

declare(strict_types=1);

use AzGuard\Contracts\Plugins\Plugin;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\RegistryFrozenException;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Plugins\PluginContext;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;

it('collects plugin and provider fields additively with contribution provenance', function (): void {
    $recipe = new PanelRecipe('admin');
    $builder = new PanelBuilder($recipe);
    $plugin = new class implements Plugin
    {
        public function id(): string
        {
            return 'notes';
        }

        public function register(PanelBuilder $panel, PluginContext $context): void
        {
            $panel->fields(FieldTarget::RoleGrant, [Field::string('note')->inMeta()]);
        }

        public function boot(Panel $panel, PluginContext $context): void {}
    };
    $builder->fields(FieldTarget::RoleGrant, [Field::int('floor')])->plugins([$plugin]);
    $compiler = new PanelCompiler;
    $compiler->register($recipe, $builder, app(), 'test');
    $panel = $compiler->compile($recipe);
    expect(array_map(fn (Field $field) => $field->name(), $panel->fields(FieldTarget::RoleGrant)))->toBe(['floor', 'note'])
        ->and($panel->fields(FieldTarget::RoleGrant)[1]->contributedBy())->toBe('plugin:notes')
        ->and($panel->fields(FieldTarget::PermissionGrant))->toBe([])
        ->and(fn () => $builder->fields(FieldTarget::RoleGrant, []))->toThrow(RegistryFrozenException::class);
});

it('rejects duplicate fields with both origins but allows the same name on different targets', function (): void {
    $recipe = new PanelRecipe('admin');
    $builder = new PanelBuilder($recipe);
    $builder->fields(FieldTarget::RoleGrant, [Field::string('note')]);
    $builder->fields(FieldTarget::PermissionGrant, [Field::string('note')]);
    expect((new PanelCompiler)->compile($recipe)->fields(FieldTarget::PermissionGrant))->toHaveCount(1);
    $recipe->during(PanelRecipe::plugin('notes', 1), fn () => $builder->fields(FieldTarget::RoleGrant, [Field::string('note')]));
    expect(fn () => (new PanelCompiler)->compile($recipe))->toThrow(DefinitionException::class, 'from provider and plugin:notes');
});

it('rejects non-field contributions', function (): void {
    expect(fn () => (new PanelBuilder(new PanelRecipe('admin')))->fields(FieldTarget::RoleGrant, ['note']))->toThrow(DefinitionException::class);
});
