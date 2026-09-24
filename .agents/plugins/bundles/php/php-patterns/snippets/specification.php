<?php

declare(strict_types=1);

namespace App\Domain\Specification;

/**
 * Specification: композируемый предикат «удовлетворяет ли кандидат правилу».
 * - один метод isSatisfiedBy(): bool;
 * - элементарные спецификации комбинируются and/or/not без правки исходных;
 * - правило бизнес-логики становится first-class объектом (имя, тест, переиспользование).
 *
 * Когда правило фильтрует выборку из БД — это model scope / queryForUser
 * (см. php/repositories), а не in-memory specification. Specification — для
 * проверки уже загруженного объекта или сложного решения «можно / нельзя».
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
        // Позиционно, не именованно: имя параметра реализации isSatisfiedBy не
        // фиксировано интерфейсом — named arg через интерфейс ломает полиморфизм.
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
