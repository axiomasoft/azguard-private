<?php

declare(strict_types=1);

namespace AzGuard\Concerns;

use AzGuard\Exceptions\TenantRequiredException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Permissions\PermissionSet;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Every panel of one subject: `$user->azguard()`. Panels are picked by the panel resolver only; the selector never
 * changes the model, the authentication manager or the current panel.
 *
 * @api
 */
final readonly class SubjectPanels
{
    public function __construct(private Model|SubjectRef $subject) {}

    /**
     * Ids of the panels that accept the subject.
     *
     * @return list<string>
     */
    public function panels(): array
    {
        return array_map(static fn (Panel $panel): string => $panel->id(), $this->resolver()->panelsOf($this->subject));
    }

    /** The panel of the subject's model: `azguardDefaultPanel()`, the default panel or the only panel. */
    public function default(): ?string
    {
        return $this->resolver()->modelPanel($this->subject)?->id();
    }

    /**
     * The subject in one panel; the same wrapper as `$user->guard($id)`.
     *
     * @throws UnknownPanelException
     */
    public function guard(string $id): SubjectAccess
    {
        return new SubjectAccess($this->resolver()->select($this->subject, panels: [$id]), $this->subject);
    }

    /**
     * Permission sets of the panels without tenants, by panel id; a panel with tenants needs a wrapper in a tenant.
     *
     * @return array<string, PermissionSet>
     */
    public function permissions(): array
    {
        $sets = [];
        foreach ($this->resolver()->panelsOf($this->subject) as $panel) {
            if ($panel->tenants()->mode() === 'none') {
                $sets[$panel->id()] = $this->guard($panel->id())->permissionSet();
            }
        }

        return $sets;
    }

    /**
     * Role keys of the subject by panel id.
     *
     * @return array<string, list<string>>
     *
     * @throws TenantRequiredException for a panel with tenants and no current tenant
     */
    public function roles(): array
    {
        $roles = [];
        foreach ($this->resolver()->panelsOf($this->subject) as $panel) {
            $roles[$panel->id()] = array_values($this->guard($panel->id())->roleNames()->all());
        }

        return $roles;
    }

    private function resolver(): PanelResolver
    {
        return app(PanelResolver::class);
    }
}
