<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Parity;

/**
 * One (panel, subject, permission, resource|context, now) of the matrix. A decision case asks whether `subject` may
 * use `key` (a permission) on client `target` in tenant `tenant`; an assignment case asks whether role `key` may be
 * assigned to `subject` in tenant `tenant` and project `target` (null: tenant-wide) until `until`. `now` is the
 * fixed clock of the CRM stand (`CrmWorld::seed()`).
 */
final readonly class ParityCase
{
    public const string DECISION = 'decision';

    public const string ASSIGNMENT = 'assignment';

    /** @param 'none'|'future'|'past' $until */
    public function __construct(
        public string $kind,
        public int $seed,
        public string $panel,
        public int $subject,
        public string $key,
        public int $tenant,
        public ?int $target,
        public string $until = 'none',
    ) {}

    /** Stable and unique within the matrix; printed by a failure. */
    public function id(): string
    {
        return $this->kind === self::DECISION ? 'seed='.$this->seed.' '.$this->group().' '.$this->member() : $this->group().' '.$this->member();
    }

    /** The question without its resource or context: one list query, one directory search. */
    public function group(): string
    {
        return $this->kind === self::DECISION
            ? sprintf('%s u%d %s t%d', $this->panel, $this->subject, $this->key, $this->tenant)
            : sprintf('%s u%d role:%s t%d', $this->panel, $this->subject, $this->key, $this->tenant);
    }

    /** The resource or context the case asks about within its group. */
    public function member(): string
    {
        return $this->kind === self::DECISION ? 'client:'.$this->target
            : ($this->target === null ? 'tenant-wide' : 'project:'.$this->target).' until:'.$this->until;
    }
}
