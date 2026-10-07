<?php

declare(strict_types=1);

namespace AzGuard\Sources\Gate;

use AzGuard\Contracts\Sources\DescribesSchema;
use AzGuard\Contracts\Sources\ProvidesPolicies;
use AzGuard\Contracts\Sources\SourceDescription;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Sources\PanelSources;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Adapts explicitly mapped native Laravel abilities as mode-aware policies.
 *
 * @api
 */
final class GateSource implements DescribesSchema, ProvidesPolicies
{
    /** @var list<PolicyBinding> */
    private array $bindings = [];

    public static function make(): static
    {
        return new self;
    }

    /** @param class-string<Model>|null $resourceModel */
    public function map(BackedEnum|string $permission, string $ability, ?string $resourceModel = null): static
    {
        $copy = clone $this;
        $copy->bindings[] = PolicyBinding::gate(permission: $permission, ability: $ability, resourceModel: $resourceModel);

        return $copy;
    }

    public function id(): string
    {
        return 'gate';
    }

    public function policies(Panel $panel): iterable
    {
        return $this->bindings;
    }

    public function describe(Panel $panel, ?TenantRef $tenant = null): SourceDescription
    {
        return new SourceDescription(id: $this->id(), class: self::class, capabilities: PanelSources::capabilities($this), dynamic: false, label: 'Gate');
    }
}
