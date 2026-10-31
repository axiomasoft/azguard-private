<?php

declare(strict_types=1);

namespace AzGuard\Filament\Exports;

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use AzGuard\Attributes\Api;
use AzGuard\Contracts\AzGuardSubject;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Reads;
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
 */
#[Api(since: '1.0')]
class AuthorizedExportCsv extends ExportCsv
{
    /** Option of an export with the guard panel, the view permission, the tenant and the model of its resource. */
    public const string OPTION = 'azguard';

    public function handle(): void
    {
        $this->records = $this->visibleRecords();

        if ($this->records === []) {
            return;
        }

        $query = EloquentSerializeFacade::unserialize($this->query)->useWritePdo();

        if ($query->getModel()::class !== ($this->options[self::OPTION]['model'] ?? null)) {
            return;
        }
        $this->query = EloquentSerializeFacade::serialize($query);
        parent::handle();
    }

    /**
     * @return array<mixed>
     */
    private function visibleRecords(): array
    {
        $authority = $this->options[self::OPTION] ?? null;
        $panel = AzGuard::currentPanel();
        $user = $this->export->user()->useWritePdo()->first();

        if (! is_array($authority) || ! is_string($authority['panel'] ?? null) || ! is_string($authority['permission'] ?? null)
            || ! is_string($authority['model'] ?? null) || ! is_subclass_of($authority['model'], Model::class)
            || $panel?->id() !== $authority['panel'] || ! $user instanceof Model || ! $user instanceof AzGuardSubject) {
            Log::warning('AzGuard: an export chunk has no authority to check its rows again; it writes none.', ['export' => $this->export->getKey()]);

            return [];
        }
        $access = AzGuard::panel($panel->id());

        if ($access->definition()->settings()->reads() !== Reads::Primary) {
            return [];
        }

        if (! array_key_exists('tenant', $authority)) {
            return [];
        }
        $tenant = $authority['tenant'];

        if (is_array($tenant) && is_string($tenant['type'] ?? null) && (is_string($tenant['id'] ?? null) || is_int($tenant['id'] ?? null))) {
            $access = $access->inTenant(TenantRef::of($tenant['type'], $tenant['id']));
        } elseif ($tenant !== null) {
            return [];
        }
        $subject = SubjectRef::of($user->getMorphClass(), ModelKey::of($user));

        if (! $access->definition()->accepts($subject)) {
            return [];
        }
        $this->export->setRelation('user', $user);
        $model = new $authority['model'];
        $keys = [];
        foreach ($this->records as $key) {
            if ((is_int($key) || is_string($key)) && ModelKey::canHold($model, (string) $key)) {
                $keys[] = (string) $key;
            }
        }

        if ($keys === []) {
            return [];
        }
        $query = $access->visibility()->visibleTo($access->definition(), $model->newQuery()->useWritePdo(), $subject,
            $authority['permission'], $access->scope());
        $visible = [];
        foreach (ModelKey::where($query, $keys)->get() as $record) {
            $request = AccessRequest::for($subject, PermissionKey::of($panel->id(), $authority['permission']))->on(null, $record)->withSubjectModel($user);

            if ($access->decide($request)->allowed()) {
                $visible[] = (string) $record->getKey();
            }
        }

        return array_values(array_filter($this->records, static fn (mixed $key): bool => (is_int($key) || is_string($key)) && in_array((string) $key, $visible, true)));
    }
}
