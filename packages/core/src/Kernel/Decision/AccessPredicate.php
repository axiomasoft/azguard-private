<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use AzGuard\Exceptions\InvalidSourceContributionException;

/**
 * Pure, immutable exact-query descriptor. Comparisons are two-valued: NULL equals
 * only NULL, belongs to an IN set only when explicitly listed, and fails ranges.
 *
 * A partition is an adapter's assertion of disjoint, exhaustive outcomes over its
 * resource universe. Shape validation cannot prove arbitrary adapter semantics.
 */
final readonly class AccessPredicate
{
    /**
     * @param  list<self>  $operands
     * @param  list<bool|int|float|string|null>  $values
     */
    private function __construct(
        public string $operation,
        public array $operands = [],
        public ?string $identifier = null,
        public array $values = [],
        public Grant|RoleContribution|null $contribution = null,
    ) {}

    public static function pass(): self
    {
        return new self('pass');
    }

    public static function deny(): self
    {
        return new self('deny');
    }

    public static function unsupported(): self
    {
        return new self('unsupported');
    }

    public static function all(self ...$predicates): self
    {
        return self::logical('all', $predicates);
    }

    public static function any(self ...$predicates): self
    {
        return self::logical('any', $predicates);
    }

    public static function not(self $predicate): self
    {
        return self::logical('not', [$predicate]);
    }

    public static function eq(string $column, bool|int|float|string|null $value): self
    {
        return self::comparison('eq', $column, [$value]);
    }

    /** @param array<mixed> $values */
    public static function in(string $column, array $values): self
    {
        return self::comparison('in', $column, $values);
    }

    public static function isNull(string $column): self
    {
        return self::comparison('is_null', $column, []);
    }

    public static function notNull(string $column): self
    {
        return self::comparison('not_null', $column, []);
    }

    public static function gte(string $column, bool|int|float|string $value): self
    {
        return self::comparison('gte', $column, [$value]);
    }

    public static function lt(string $column, bool|int|float|string $value): self
    {
        return self::comparison('lt', $column, [$value]);
    }

    public static function exists(string $relation, self $predicate): self
    {
        foreach (explode('.', $relation) as $segment) {
            self::assertIdentifier($segment);
        }
        $predicate->assertBoolean();

        return new self('exists', [$predicate], $relation);
    }

    public static function partition(self $allow, self $deny, self $abstain): self
    {
        return self::logical('partition', [$allow, $deny, $abstain]);
    }

    public static function beforePartition(self $deny, self $pass): self
    {
        return self::logical('before_partition', [$deny, $pass]);
    }

    /** Constants preserve the scalar policy's true / false / null outcomes. */
    public static function policyResult(?bool $result): self
    {
        return self::partition(
            $result === true ? self::pass() : self::deny(),
            $result === false ? self::pass() : self::deny(),
            $result === null ? self::pass() : self::deny(),
        );
    }

    public static function beforeResult(BeforeResult $result): self
    {
        return self::beforePartition(
            $result === BeforeResult::Deny ? self::pass() : self::deny(),
            $result === BeforeResult::Continue ? self::pass() : self::deny(),
        );
    }

    public function assertPartition(bool $before = false): void
    {
        if ($this->operation !== ($before ? 'before_partition' : 'partition')) {
            throw new InvalidSourceContributionException('Expected an exact '.($before ? 'deny/pass' : 'allow/deny/abstain').' partition.');
        }
    }

    public function outcome(string $outcome): self
    {
        $outcomes = match ($this->operation) {
            'partition' => ['allow', 'deny', 'abstain'],
            'before_partition' => ['deny', 'pass'],
            default => [],
        };
        $index = array_search($outcome, $outcomes, true);

        if ($index === false) {
            throw new InvalidSourceContributionException('Invalid exact predicate outcome.');
        }

        return $this->isSupported() ? $this->operands[$index] : self::unsupported();
    }

    /** Keep every qualification of one concrete contribution inside its OR branch. */
    public static function branch(Grant|RoleContribution $contribution, self $predicate): self
    {
        $predicate->assertBoolean();
        $predicate->assertContribution($contribution);

        return new self('branch', [$predicate], contribution: $contribution);
    }

    public function assertContribution(Grant|RoleContribution $contribution): void
    {
        if ($this->contribution !== null && $this->contribution !== $contribution) {
            throw new InvalidSourceContributionException('A predicate branch cannot borrow another contribution witness.');
        }
        foreach ($this->operands as $operand) {
            $operand->assertContribution($contribution);
        }
    }

    public function isSupported(): bool
    {
        if ($this->operation === 'unsupported') {
            return false;
        }
        foreach ($this->operands as $operand) {
            if (! $operand->isSupported()) {
                return false;
            }
        }

        return true;
    }

    public function assertBoolean(): void
    {
        if (in_array($this->operation, ['partition', 'before_partition'], true)) {
            throw new InvalidSourceContributionException('Select a partition outcome before composing a boolean predicate.');
        }
    }

    /** @param array<int|string, self> $predicates */
    private static function logical(string $operation, array $predicates): self
    {
        if (! array_is_list($predicates)) {
            throw new InvalidSourceContributionException('Predicate operands must be a list, without named arguments.');
        }
        foreach ($predicates as $predicate) {
            $predicate->assertBoolean();
        }

        if (in_array($operation, ['partition', 'before_partition'], true)) {
            $operations = array_map(static fn (self $predicate): string => $predicate->operation, $predicates);

            if (array_diff($operations, ['pass', 'deny']) === [] && count(array_filter($operations, static fn (string $op): bool => $op === 'pass')) !== 1) {
                throw new InvalidSourceContributionException('Constant partition outcomes must be disjoint and exhaustive.');
            }
        }

        return new self($operation, $predicates);
    }

    /** @param array<mixed> $values */
    private static function comparison(string $operation, string $column, array $values): self
    {
        self::assertIdentifier($column);

        if (! array_is_list($values)) {
            throw new InvalidSourceContributionException('Predicate values must be a list.');
        }
        $plain = [];
        foreach ($values as $value) {
            if (($value !== null && ! is_scalar($value)) || (is_float($value) && ! is_finite($value))) {
                throw new InvalidSourceContributionException('Predicate bindings must be finite scalar values or NULL.');
            }
            $plain[] = $value;
        }

        return new self($operation, identifier: $column, values: $plain);
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $identifier) !== 1) {
            throw new InvalidSourceContributionException('Predicate identifiers must be plain column or relation names.');
        }
    }
}
