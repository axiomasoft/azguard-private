<?php

declare(strict_types=1);

namespace App\Domain\Specification;

/**
 * Specification: composable predicate «does the candidate satisfy the rule».
 * - one method isSatisfiedBy(): bool;
 * - elementary specifications are combined and/or/not without editing the original ones;
 * - business logic rule becomes first-class object (name, test, reuse).
 *
 * When a rule filters a sample from a database, it model scope / queryForUser
 * (see php/repositories), and not in-memory specification. Specification — for
 * checking an already loaded object or complex solution «you can / not possible».
 *
 * @template T of object
 */
interface Specification
{
    /** @param T $candidate */
    public function isSatisfiedBy(object $candidate): bool;
}

/**
 * @template T of object
 * @implements Specification<T>
 */
abstract class CompositeSpecification implements Specification
{
    /**
     * @param  Specification<T>  $other
     * @return Specification<T>
     */
    public function and(Specification $other): Specification
    {
        return new AndSpecification(left: $this, right: $other);
    }

    /** @return Specification<T> */
    public function not(): Specification
    {
        return new NotSpecification(spec: $this);
    }
}

/**
 * @template T of object
 * @extends CompositeSpecification<T>
 */
final class AndSpecification extends CompositeSpecification
{
    /**
     * @param  Specification<T>  $left
     * @param  Specification<T>  $right
     */
    public function __construct(
        private readonly Specification $left,
        private readonly Specification $right,
    ) {}

    public function isSatisfiedBy(object $candidate): bool
    {
        // Positional, unnamed: implementation parameter name isSatisfiedBy not
        // fixed by interface - named arg through the interface breaks polymorphism.
        return $this->left->isSatisfiedBy($candidate)
            && $this->right->isSatisfiedBy($candidate);
    }
}

/**
 * @template T of object
 * @extends CompositeSpecification<T>
 */
final class NotSpecification extends CompositeSpecification
{
    /** @param Specification<T> $spec */
    public function __construct(private readonly Specification $spec) {}

    public function isSatisfiedBy(object $candidate): bool
    {
        return ! $this->spec->isSatisfiedBy($candidate);
    }
}
