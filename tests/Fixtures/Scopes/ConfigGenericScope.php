<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Scopes;

use AzGuard\Contracts\Scopes\AssignmentScopeFilter;
use AzGuard\Contracts\Scopes\ConfigurableAssignmentScopeDefinition;
use AzGuard\Scopes\AssignmentScopeSettings;
use Closure;

final class ConfigGenericScope extends StoreScope implements ConfigurableAssignmentScopeDefinition
{
    private AssignmentScopeSettings $configuration;

    public function __construct()
    {
        parent::__construct();
        $this->configuration = new AssignmentScopeSettings([], null, null);
    }

    public function filter(AssignmentScopeFilter|string|Closure $filter): static
    {
        $copy = clone $this;
        $copy->configuration = new AssignmentScopeSettings([...$this->configuration->filters, $filter], $this->configuration->label, $this->configuration->directory);

        return $copy;
    }

    public function label(string $label): static
    {
        $copy = clone $this;
        $copy->configuration = new AssignmentScopeSettings($this->configuration->filters, $label, $this->configuration->directory);

        return $copy;
    }

    public function directory(string $class): static
    {
        $copy = clone $this;
        $copy->configuration = new AssignmentScopeSettings($this->configuration->filters, $this->configuration->label, $class);

        return $copy;
    }

    public function settings(): AssignmentScopeSettings
    {
        return $this->configuration;
    }
}
