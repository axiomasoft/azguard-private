<?php

declare(strict_types=1);

namespace AzGuard\Filament;

use AzGuard\Filament\Pages\DoctorPage;
use AzGuard\Filament\Resources\DirectGrantResource;
use AzGuard\Filament\Resources\RoleResource;
use AzGuard\Panels\PanelResolver;
use BackedEnum;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Resources\Resource;
use Override;

/**
 * AzGuard Filament plugin (Filament 5).
 *
 * Register in your PanelProvider:
 *
 * $panel->plugins([
 *     AzGuardPlugin::make()->forPanel('admin'),
 * ])
 */
final class AzGuardPlugin implements Plugin
{
    private ?string $panelId = null;

    private ?bool $enforce = null;

    private ?string $source = null;

    /** @var list<string>|null */
    private ?array $abilities = null;

    private ?string $keyTemplate = null;

    private ?string $case = null;

    /**
     * Resolved through the container (not `new self`) so tests and consuming
     * apps can swap in a bound alternative via `app()->bind(self::class, ...)`.
     */
    public static function make(): static
    {
        return app(self::class);
    }

    #[Override]
    public function getId(): string
    {
        return 'az-guard';
    }

    /**
     * Specify which AzGuard panel the management UI is shown for.
     * Allows filtering permissions and roles to that panel only.
     */
    public function forPanel(string|BackedEnum $panelId): static
    {
        $this->panelId = PanelResolver::normalizeId($panelId);

        return $this;
    }

    public function getPanelId(): string
    {
        if ($this->panelId !== null) {
            return $this->panelId;
        }

        return config('az-guard-filament.panel', 'admin');
    }

    /** Enable/disable zero-boilerplate Gate enforcement for discovered resources. */
    public function enforce(bool $enforce = true): static
    {
        $this->enforce = $enforce;

        return $this;
    }

    public function isEnforcing(): bool
    {
        return $this->enforce ?? (bool) config('az-guard-filament.enforce', true);
    }

    /** @param  'database'|'enum'|'policy'  $source */
    public function source(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source ?? (string) config('az-guard-filament.source', 'database');
    }

    /** @param  list<string>  $abilities */
    public function abilities(array $abilities): static
    {
        $this->abilities = $abilities;

        return $this;
    }

    /** @return list<string> */
    public function getAbilities(): array
    {
        /** @var list<string> $abilities */
        $abilities = $this->abilities ?? config('az-guard-filament.abilities', []);

        return $abilities;
    }

    /** Permission-key template. Placeholders: {panel}, {resource}, {ability}. */
    public function keyTemplate(string $template): static
    {
        $this->keyTemplate = $template;

        return $this;
    }

    public function getKeyTemplate(): string
    {
        return $this->keyTemplate ?? (string) config('az-guard-filament.key', '{panel}.{resource}.{ability}');
    }

    /** @param  'snake'|'kebab'|'camel'|'none'  $case */
    public function case(string $case): static
    {
        $this->case = $case;

        return $this;
    }

    public function getCase(): string
    {
        return $this->case ?? (string) config('az-guard-filament.case', 'snake');
    }

    #[Override]
    public function register(Panel $panel): void
    {
        // Fluent options, when set, are the effective value; config remains the
        // fallback for consumers (PermissionSchema/discovery/gate) that resolve
        // it independently of this plugin instance — keep them in sync here.
        config([
            'az-guard-filament.enforce' => $this->isEnforcing(),
            'az-guard-filament.source' => $this->getSource(),
            'az-guard-filament.abilities' => $this->getAbilities(),
            'az-guard-filament.key' => $this->getKeyTemplate(),
            'az-guard-filament.case' => $this->getCase(),
        ]);

        $panel
            ->resources([
                RoleResource::class,
                DirectGrantResource::class,
            ])
            ->pages([
                DoctorPage::class,
            ]);
    }

    #[Override]
    public function boot(Panel $panel): void
    {
        // For the "policy" source, generated policies enforce via Filament's
        // native authorization, so leave the policy-existence check in place.
        if (! $this->isEnforcing() || $this->getSource() === 'policy') {
            return;
        }

        // Force Filament to consult the Gate for every resource (instead of
        // allowing when no policy exists) so AzGuard's ResourceGate enforces
        // the generated permissions — no per-resource code required.
        foreach ($panel->getResources() as $resource) {
            if (is_subclass_of($resource, Resource::class)) {
                $resource::checkPolicyExistence(false);
            }
        }
    }
}
