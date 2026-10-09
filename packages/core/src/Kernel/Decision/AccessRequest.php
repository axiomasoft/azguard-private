<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;

/**
 * "May this subject do this?": the permission plus an explicit tenant, assignment scope and resource.
 *
 * A null tenant or context means "not given"; resolvers fill it later. The global context is passed explicitly.
 */
final readonly class AccessRequest
{
    private function __construct(
        private SubjectRef $subject,
        private PermissionKey $permission,
        private ?TenantRef $tenant = null,
        private ?AssignmentScopeRef $context = null,
        private ?object $resource = null,
        private bool $traced = false,
        private ?object $subjectModel = null,
    ) {}

    public static function for(SubjectRef $subject, PermissionKey $permission): self
    {
        return new self($subject, $permission);
    }

    public function inTenant(TenantRef $tenant): self
    {
        return new self($this->subject, $this->permission, $tenant, $this->context, $this->resource, $this->traced, $this->subjectModel);
    }

    /**
     * Sets the assignment scope and resource; the tenant stays as it was.
     */
    public function on(?AssignmentScopeRef $context, ?object $resource = null): self
    {
        return new self($this->subject, $this->permission, $this->tenant, $context, $resource, $this->traced, $this->subjectModel);
    }

    public function inScope(AccessScope $scope, ?object $resource = null): self
    {
        return new self($this->subject, $this->permission, $scope->tenant, $scope->context, $resource, $this->traced, $this->subjectModel);
    }

    public function traced(bool $trace = true): self
    {
        return new self($this->subject, $this->permission, $this->tenant, $this->context, $this->resource, $trace, $this->subjectModel);
    }

    /**
     * The subject model the caller already holds. Checks use it instead of reading the row again, as Laravel's Gate
     * uses the user instance it is given. Call refresh() on the model to see changes made elsewhere.
     */
    public function withSubjectModel(object $model): self
    {
        return new self($this->subject, $this->permission, $this->tenant, $this->context, $this->resource, $this->traced, $model);
    }

    public function subjectModel(): ?object
    {
        return $this->subjectModel;
    }

    public function subject(): SubjectRef
    {
        return $this->subject;
    }

    public function permission(): PermissionKey
    {
        return $this->permission;
    }

    public function tenant(): ?TenantRef
    {
        return $this->tenant;
    }

    public function context(): ?AssignmentScopeRef
    {
        return $this->context;
    }

    public function resource(): ?object
    {
        return $this->resource;
    }

    public function isTraced(): bool
    {
        return $this->traced;
    }
}
