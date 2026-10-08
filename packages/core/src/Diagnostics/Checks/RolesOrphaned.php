<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Database\DatabaseSource;
use DateTimeImmutable;
use DateTimeZone;

/**
 * `roles.orphaned` of a database source: stored role grants that give nothing. A role key the PHP catalog no longer
 * has is a warning, its grants may be cleaned up; grants of a known role stored where that role cannot apply (an
 * unbound scope type, tenant-wide for a role that requires a scope, a role that is not grantable) are an error.
 *
 * @internal
 */
final readonly class RolesOrphaned implements DoctorCheck
{
    public function __construct(private DatabaseSource $source) {}

    public function key(): string
    {
        return 'roles.orphaned';
    }

    public function run(DoctorContext $context): iterable
    {
        $panel = $context->panel();

        if ($panel === null) {
            return;
        }
        $roles = app(PanelRegistry::class)->catalog($panel->id())->roles();
        $former = [];
        foreach ($roles as $key => $role) {
            foreach ($role['former_keys'] as $old) {
                $former[$old] = (string) $key;
            }
        }

        foreach ($this->source->orphanedGrants($panel, new DateTimeImmutable('now', new DateTimeZone('UTC'))) as $orphan) {
            if ($orphan['kind'] !== 'role') {
                continue;
            }
            $details = ['role' => $orphan['name'], 'tenant' => $orphan['tenant'], 'origin' => $orphan['origin'], 'grants' => $orphan['rows']];

            if (isset($roles[$orphan['name']])) {
                yield DoctorFinding::error($this->key(), $orphan['rows'].' grant(s) of the role "'.$orphan['name'].'" in tenant '.$orphan['tenant'].' (origin '.$orphan['origin']
                    .') are stored where the role cannot apply, so they give nothing.', details: $details);

                continue;
            }

            yield DoctorFinding::warning($this->key(), $orphan['rows'].' grant(s) of the role "'.$orphan['name'].'" in tenant '.$orphan['tenant'].' (origin '.$orphan['origin'].') '
                .(isset($former[$orphan['name']])
                    ? 'use a former key of "'.$former[$orphan['name']].'": migrate them to the current key.'
                    : 'name a role the PHP catalog no longer has: they give nothing and can be cleaned up.'), details: $details);
        }
    }
}
