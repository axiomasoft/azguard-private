<?php

declare(strict_types=1);

namespace AzGuard\Scopes\Testing;

use ArrayObject;
use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Context\AssignmentScopeRuntime;
use AzGuard\Contracts\Scopes\QueryableAssignmentScopeDefinition;
use AzGuard\Scopes\Contracts\AssignmentScopeFilter;
use AzGuard\Scopes\Internal\Query\EligibilityBuilder;
use AzGuard\Support\ModelKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * For the authors of assignment-scope filters: what every filter guarantees, checked with the module's own guard.
 *
 * Use the trait in a test case that boots a Laravel application with the table of the definition's model, and implement
 * `azguardFilter()`, `azguardDefinition()` and `azguardRuntime()`. The suite applies the filter to the structural query of the definition
 * exactly as the Scopes module does, with that runtime.
 *
 * - it only narrows: every record it keeps is a record of the structural query, and it adds nothing but conditions
 *   (no join, order, limit, grouping or union);
 * - it passes the guard the module puts around it: no terminal call, no write, no replaced query, no returned builder;
 * - it is pure for one runtime: applying it twice gives the same conditions, bindings and records;
 * - it writes nothing while it narrows.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
trait AssignmentScopeFilterContractTests
{
    /** A new instance of the filter under test. */
    abstract protected function azguardFilter(): AssignmentScopeFilter;

    /** The definition whose structural query the filter narrows; its table holds at least one record. */
    abstract protected function azguardDefinition(): QueryableAssignmentScopeDefinition;

    /**
     * The runtime the filter is applied with: its panel, a subject, and the scope of one record of the definition.
     * Build it like the panel of the application does; the suite reads nothing from it but what the filter reads.
     */
    abstract protected function azguardRuntime(): AssignmentScopeRuntime;

    #[Test]
    public function filterOnlyNarrowsTheStructuralQuery(): void
    {
        $structural = $this->azguardKeys(EligibilityBuilder::structural($this->azguardDefinition()));
        $narrowed = $this->azguardKeys($this->azguardNarrowed());

        $this->assertSame([], array_values(array_diff($narrowed, $structural)), 'The filter keeps a record the structural query does not.');
    }

    #[Test]
    public function filterAddsOnlyConditions(): void
    {
        $base = $this->azguardNarrowed()->getQuery();

        foreach (['joins', 'groups', 'havings', 'orders', 'unions', 'limit', 'offset', 'lock'] as $part) {
            $this->assertEmpty($base->{$part}, "The filter added a {$part} clause; it may only add conditions.");
        }
    }

    #[Test]
    public function filterPassesTheGuardOfTheModule(): void
    {
        $this->assertNotNull($this->azguardNarrowed());
    }

    #[Test]
    public function filterIsPureForOneRuntime(): void
    {
        $runtime = $this->azguardRuntime();
        $first = $this->azguardNarrowed($runtime);
        $second = $this->azguardNarrowed($runtime);

        $this->assertSame($first->toSql(), $second->toSql(), 'The filter adds different conditions for the same runtime.');
        $this->assertSame($first->getBindings(), $second->getBindings(), 'The filter binds different values for the same runtime.');
        $this->assertSame($this->azguardKeys($first), $this->azguardKeys($second), 'The filter keeps different records for the same runtime.');
    }

    #[Test]
    public function filterWritesNothingWhileItNarrows(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject;
        app('events')->listen(QueryExecuted::class, static function (QueryExecuted $query) use ($log): void {
            if (preg_match('/\A\s*(?:insert|update|delete|replace|create|drop|alter|truncate)\b/i', $query->sql) === 1) {
                $log->append($query->sql);
            }
        });
        $this->azguardKeys($this->azguardNarrowed());
        $writes = $log->getArrayCopy();

        $this->assertSame([], $writes, 'The filter wrote while it narrowed: '.implode('; ', $writes));
    }

    /** @return Builder<Model> the structural query with the filter applied through the module's guard */
    private function azguardNarrowed(?AssignmentScopeRuntime $runtime = null): Builder
    {
        $definition = $this->azguardDefinition();
        $query = EligibilityBuilder::structural($definition);

        try {
            EligibilityBuilder::apply($definition, $query, [$this->azguardFilter()], $runtime ?? $this->azguardRuntime(), app());
        } catch (RuntimeException $rejected) {
            $this->fail('The guard of the module refused the filter: '.$rejected->getMessage());
        }

        return $query;
    }

    /**
     * @param  Builder<Model>  $query
     * @return list<string>
     */
    private function azguardKeys(Builder $query): array
    {
        $keys = array_map(ModelKey::of(...), $query->get()->all());
        sort($keys);

        return $keys;
    }
}
