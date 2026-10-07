<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Contracts\Changes\GrantManager;
use AzGuard\Exceptions\PanelNotWritableException;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Database\DatabaseSource;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The grants of one panel, tenant and origin. Reads go to the database writer of the panel, changes to the change
 * pipeline with the partition of this manager; the caller can name ids, never a partition.
 *
 * A page cursor carries the last id and a digest of the partition, the filter and the code build: a cursor of another
 * manager, filter or build is refused rather than followed.
 *
 * @internal made by the panel managers
 */
final readonly class ScopedGrantManager implements GrantManager
{
    public function __construct(
        private Container $container,
        private ChangePipeline $pipeline,
        private Panel $panel,
        private TenantRef $tenant,
        private string $origin,
    ) {}

    public function page(GrantFilter $filter): GrantPage
    {
        if ($filter->role !== null && $filter->role->panel() !== $this->panel->id()
            || $filter->permission !== null && $filter->permission->panel() !== $this->panel->id()) {
            throw new InvalidArgumentException('A grant filter of panel '.$this->panel->id().' names a key of another panel.');
        }
        $after = $filter->cursor === null ? null : $this->decode($filter->cursor, $filter);
        $now = CarbonImmutable::now('UTC')->startOfSecond()->toDateTimeImmutable();
        $records = $this->writer()->inspectGrants($this->panel, $this->tenant, $this->origin, $filter, $after, $now, $filter->limit + 1);

        if (count($records) <= $filter->limit) {
            return new GrantPage($records, null);
        }
        $records = array_slice($records, 0, $filter->limit);

        return new GrantPage($records, $this->encode($records[$filter->limit - 1]->id, $filter));
    }

    public function find(string $id): ?GrantRecord
    {
        return $this->writer()->inspectGrant($this->panel, $this->tenant, $this->origin, $id);
    }

    public function update(string $id, GrantDetails $details, ?string $expectedFingerprint = null): ChangeResult
    {
        return $this->pipeline->update($this->panel, $this->tenant, $this->origin, $id, $details, $expectedFingerprint);
    }

    public function revokeMany(array $ids): ChangeResult
    {
        return $this->pipeline->revokeIds($this->panel, $this->tenant, $this->origin, self::ids($ids));
    }

    /**
     * Ids come from a request: a list of strings is checked, not assumed.
     *
     * @param  array<mixed>  $ids
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        if (! array_is_list($ids)) {
            throw new InvalidArgumentException('Grant ids are a list.');
        }

        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new InvalidArgumentException('A grant id is a string such as "role:12".');
            }
        }

        return $ids;
    }

    private function writer(): DatabaseSource
    {
        $writer = $this->panel->writer();

        return $writer instanceof DatabaseSource ? $writer
            : throw new PanelNotWritableException('Panel '.$this->panel->id().' has no database writer to read grants from.');
    }

    private function encode(string $id, GrantFilter $filter): string
    {
        return rtrim(strtr(base64_encode(json_encode([$id, $this->digest($filter)], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{'role'|'permission', int} */
    private function decode(string $cursor, GrantFilter $filter): array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $value = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($value) || count($value) !== 2 || ! is_string($value[0] ?? null) || ! is_string($value[1] ?? null)
            || preg_match('/\A(role|permission):([1-9][0-9]{0,18})\z/', $value[0], $parts) !== 1) {
            throw new InvalidArgumentException('The cursor is not a cursor of a grant page.');
        }

        if (! hash_equals($this->digest($filter), $value[1])) {
            throw new InvalidArgumentException('The cursor belongs to another panel, tenant, origin, filter or code build.');
        }

        return [$parts[1] === 'role' ? 'role' : 'permission', (int) $parts[2]];
    }

    private function digest(GrantFilter $filter): string
    {
        return IdentityCodec::digest(['grant-page', $this->panel->id(), $this->tenant->key(), $this->origin,
            $this->container->make(PanelRegistry::class)->fingerprint($this->panel->id()), ...$filter->conditions()]);
    }
}
