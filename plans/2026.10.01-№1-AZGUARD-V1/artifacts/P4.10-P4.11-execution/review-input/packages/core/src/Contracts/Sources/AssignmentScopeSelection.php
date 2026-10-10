<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Exceptions\InvalidSourceContributionException;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Identity\AssignmentScopeRef;

/**
 * Candidate prefilter with raw witnesses. It never represents an Allow.
 *
 * @spi
 */
final readonly class AssignmentScopeSelection
{
    /** @param list<AssignmentScopeRef> $refs
     * @param  list<Grant|RoleContribution>  $contributions
     */
    private function __construct(private bool $everywhere, private array $refs, private array $contributions) {}

    /** @param list<Grant|RoleContribution> $contributions */
    public static function everywhere(array $contributions): self
    {
        return new self(true, [], self::witnesses($contributions));
    }

    /** @param list<AssignmentScopeRef> $refs
     * @param  list<Grant|RoleContribution>  $contributions
     */
    public static function in(array $refs, array $contributions): self
    {
        $refs = self::validatedRefs($refs);

        return new self(false, $refs, self::witnesses($contributions));
    }

    /** @param array<mixed> $refs
     * @return list<AssignmentScopeRef>
     */
    private static function validatedRefs(array $refs): array
    {
        if (! array_is_list($refs)) {
            throw new InvalidSourceContributionException('Selection refs must be a list.');
        }
        foreach ($refs as $ref) {
            if (! $ref instanceof AssignmentScopeRef || $ref->isGlobal()) {
                throw new InvalidSourceContributionException('Selection refs must be concrete assignment scope references.');
            }
        }

        return $refs;
    }

    public static function nowhere(): self
    {
        return new self(false, [], []);
    }

    public function isEverywhere(): bool
    {
        return $this->everywhere;
    }

    /** @return list<AssignmentScopeRef> */
    public function refs(): array
    {
        return $this->refs;
    }

    /** @return list<Grant|RoleContribution> */
    public function contributions(): array
    {
        return $this->contributions;
    }

    /** @param array<mixed> $contributions
     * @return list<Grant|RoleContribution>
     */
    private static function witnesses(array $contributions): array
    {
        if (! array_is_list($contributions)) {
            throw new InvalidSourceContributionException('Selection contributions must be a list.');
        }
        foreach ($contributions as $item) {
            if (! $item instanceof Grant && ! $item instanceof RoleContribution) {
                throw new InvalidSourceContributionException('Selection witness must be a grant or role contribution.');
            }
        }

        return $contributions;
    }
}
