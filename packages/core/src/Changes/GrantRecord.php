<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use DateTimeImmutable;

/**
 * One stored grant as the writer read it under the panel lock.
 *
 * `id` is `role:<n>` or `permission:<n>`. `fingerprint` covers the build, the id, the key, the expiry, the fields and
 * the last update; a form that kept it can be saved only while it still matches.
 *
 * @api
 */
final readonly class GrantRecord
{
    /**
     * @internal built by the writer
     *
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public string $id,
        public string $panel,
        public AccessScope $scope,
        public SubjectRef $subject,
        public ?RoleKey $role,
        public ?PermissionPattern $permission,
        public string $origin,
        public ?DateTimeImmutable $until,
        public array $fields,
        public ?ActorRef $actor,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
        public string $fingerprint,
    ) {}

    public function isRole(): bool
    {
        return $this->role !== null;
    }
}
