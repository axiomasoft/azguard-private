<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Query;

use Illuminate\Database\Query\Builder;
use RuntimeException;

/** Query construction only; the unguarded core query executes after validation. */
final class PredicateQuery extends Builder
{
    public function __construct(Builder $source, private QueryGuard $guard)
    {
        parent::__construct($source->getConnection(), $source->getGrammar(), $source->getProcessor());
        foreach (get_object_vars($source) as $name => $value) {
            $this->{$name} = $value;
        }
    }

    public function newQuery(): self
    {
        return new self(new Builder($this->connection, $this->grammar, $this->processor), $this->guard);
    }

    public function applyBeforeQueryCallbacks(): never
    {
        $this->guard->reject();
    }

    protected function runSelect(): never
    {
        $this->guard->reject();
    }

    public function cursor(): never
    {
        $this->guard->reject();
    }

    public function explain(): never
    {
        $this->guard->reject();
    }

    public function toSql(): string
    {
        // Subqueries need SQL compilation, which Laravel normally routes through beforeQuery.
        if ($this->beforeQueryCallbacks !== []) {
            throw new RuntimeException('Eligibility predicates cannot register query execution callbacks.');
        }

        return $this->grammar->compileSelect($this);
    }
}
