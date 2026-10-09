<?php

declare(strict_types=1);

namespace AzGuard\Filament\Exports;

use AzGuard\Contracts\AzGuardSubject;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Support\ModelKey;
use Filament\Actions\Exports\Jobs\ExportCsv;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Writes a chunk of an export with only the rows the user of the export may still view.
 *
 * The query of an export is serialized when the export starts; Filament does not authorize the rows again. This job
 * keeps, of the keys of its chunk, those that the view permission of the resource still lets the user see, in the guard
 * panel that the job runs in (the panel of the request that started the export) and the tenant of the export. A missing
 * panel, permission, user or tenant writes no row.
 *
 * @api
 */
class AuthorizedExportCsv extends ExportCsv
{
    /** Option of an export with the guard panel, the view permission, the tenant and the model of its resource. */
    public const string OPTION = 'azguard';

    public function handle(): void
    {
        $this->records = $this->visibleRecords();

        parent::handle();
    }

    /**
     * @return array<mixed>
     */
    private function visibleRecords(): array
    {
        $authority = $this->options[self::OPTION] ?? null;
        $panel = AzGuard::currentPanel();
        $user = $this->export->user;

        if (! is_array($authority) || ! is_string($authority['panel'] ?? null) || ! is_string($authority['permission'] ?? null)
            || ! is_string($authority['model'] ?? null) || ! is_subclass_of($authority['model'], Model::class)
            || $panel?->id() !== $authority['panel'] || ! $user instanceof Model || ! $user instanceof AzGuardSubject) {
            Log::warning('AzGuard: an export chunk has no authority to check its rows again; it writes none.', ['export' => $this->export->getKey()]);

            return [];
        }
        $access = AzGuard::panel($panel->id());
        $tenant = $authority['tenant'] ?? null;

        if (is_array($tenant) && is_string($tenant['type'] ?? null) && (is_string($tenant['id'] ?? null) || is_int($tenant['id'] ?? null))) {
            $access = $access->inTenant(TenantRef::of($tenant['type'], $tenant['id']));
        }
        $model = new $authority['model'];
        $query = $access->visibility()->visibleTo($access->definition(), $model->newQuery(), SubjectRef::of($user->getMorphClass(), ModelKey::of($user)),
            $authority['permission'], $access->scope());
        $visible = [];
        foreach ($query->whereKey($this->records)->pluck($model->getQualifiedKeyName())->all() as $key) {
            if (is_int($key) || is_string($key)) {
                $visible[] = (string) $key;
            }
        }

        return array_values(array_filter($this->records, static fn (mixed $key): bool => (is_int($key) || is_string($key)) && in_array((string) $key, $visible, true)));
    }
}
