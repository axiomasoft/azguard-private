<?php

declare(strict_types=1);

namespace AzGuard\Filament\Internal\Exports;

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use AzGuard\Contracts\AzGuardSubject;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Exports\AuthorizedExportCsv;
use AzGuard\Filament\Exports\AuthorizedPrepareCsvExport;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Reads;
use AzGuard\Support\ModelKey;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Refuses to prepare an export when no selected record is currently authorized.
 *
 * @internal
 */
final class ChecksPrepareCsvExport extends AuthorizedPrepareCsvExport
{
    public function handle(): void
    {
        if (! $this->hasAuthorizedRecord()) {
            return;
        }

        parent::handle();
    }

    private function hasAuthorizedRecord(): bool
    {
        try {
            $authority = $this->options[AuthorizedExportCsv::OPTION] ?? null;
            $panel = AzGuard::currentPanel();
            $user = $this->export->user()->useWritePdo()->first();

            if (! is_array($authority) || ! is_string($authority['panel'] ?? null) || $panel?->id() !== $authority['panel']
                || ! is_string($authority['permission'] ?? null) || ! is_string($authority['model'] ?? null)
                || ! is_subclass_of($authority['model'], Model::class) || ! array_key_exists('tenant', $authority)
                || ! $user instanceof Model || ! $user instanceof AzGuardSubject) {
                return false;
            }
            $access = AzGuard::panel($panel->id());

            if ($access->definition()->settings()->reads() !== Reads::Primary) {
                return false;
            }
            $tenant = $authority['tenant'];

            if (is_array($tenant) && is_string($tenant['type'] ?? null) && (is_string($tenant['id'] ?? null) || is_int($tenant['id'] ?? null))) {
                $access = $access->inTenant(TenantRef::of($tenant['type'], $tenant['id']));
            } elseif ($tenant !== null) {
                return false;
            }
            $subject = SubjectRef::of($user->getMorphClass(), ModelKey::of($user));

            if (! $access->definition()->accepts($subject)) {
                return false;
            }
            $query = EloquentSerializeFacade::unserialize($this->query)->useWritePdo();
            $model = $query->getModel();

            if ($model::class !== $authority['model']) {
                return false;
            }
            $this->query = EloquentSerializeFacade::serialize($query);

            if ($this->records !== null) {
                $keys = [];
                foreach ($this->records as $key) {
                    if ((is_string($key) || is_int($key)) && ModelKey::canHold($model, (string) $key)) {
                        $keys[] = (string) $key;
                    }
                }

                if ($keys === []) {
                    return false;
                }
                ModelKey::where($query, $keys);
            }
            $query = $access->visibility()->visibleTo($access->definition(), $query, $subject, $authority['permission'], $access->scope());

            // Authorization can query this PDO too; materialize only one bounded page at a time.
            $authorized = false;
            (clone $query)->each(static function (Model $record) use ($subject, $panel, $user, $authority, $access, &$authorized): bool {
                $request = AccessRequest::for($subject, PermissionKey::of($panel->id(), $authority['permission']))->on(null, $record)->withSubjectModel($user);
                $authorized = $access->decide($request)->allowed();

                return ! $authorized;
            }, 100);

            return $authorized;
        } catch (Throwable) {
            return false;
        }
    }
}
