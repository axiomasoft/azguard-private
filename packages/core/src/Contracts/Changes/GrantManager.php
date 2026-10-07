<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Changes;

use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\GrantDetails;
use AzGuard\Changes\GrantFilter;
use AzGuard\Changes\GrantPage;
use AzGuard\Changes\GrantRecord;
use AzGuard\Exceptions\StaleSelectionException;

/**
 * The stored grants of one panel, tenant and origin, for an editor. The partition is fixed when the manager is made:
 * no method takes a panel, a tenant or an origin, and a grant of another partition behaves as if it did not exist.
 * Every change goes through the change pipeline, its pipes and its final validation.
 *
 * @api
 */
interface GrantManager
{
    /** Grants of the partition matching the filter, expired and orphaned ones included as the filter asks. */
    public function page(GrantFilter $filter): GrantPage;

    /** A grant of the partition by id, whatever its state; a grant of another panel, tenant or origin is null. */
    public function find(string $id): ?GrantRecord;

    /**
     * Replaces expiry and fields of one grant and validates it again as an assignment; its role, subject, scope and
     * origin never change. With `$expectedFingerprint` the grant must still be the one the form read.
     *
     * @throws StaleSelectionException when the grant is not in the partition or changed since it was read
     */
    public function update(string $id, GrantDetails $details, ?string $expectedFingerprint = null): ChangeResult;

    /**
     * Revokes the grants in one mutation, each from its stored scope, also when its role, scope or permission is gone.
     * One id outside the partition refuses the whole operation before anything runs.
     *
     * @param  list<string>  $ids
     *
     * @throws StaleSelectionException when an id is not a grant of the partition
     */
    public function revokeMany(array $ids): ChangeResult;
}
