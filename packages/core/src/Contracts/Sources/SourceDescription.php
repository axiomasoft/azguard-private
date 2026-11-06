<?php

declare(strict_types=1);

namespace AzGuard\Contracts\Sources;

use AzGuard\Attributes\Api;
use AzGuard\Schema\Field;

/**
 * What a source can contribute to a panel. Parameters of the source are not part of the description.
 *
 * @phpstan-type CapabilityCell array{status: 'supported'|'unsupported'|'conditional', basis: 'static_declaration', reason: string, test_reference: string}
 */
#[Api(since: '1.0')]
final readonly class SourceDescription
{
    /** A name of the source for people; the id when the source gives none. */
    public string $label;

    /** @var array<string, CapabilityCell> Declarations, never a runtime authorization or SQL-equivalence verdict. */
    public array $matrix;

    /**
     * @param  string  $id  id of the source inside the panel
     * @param  class-string<Source>  $class
     * @param  list<class-string<Source>>  $capabilities  capability interfaces the source implements
     * @param  array<string, list<Field>>  $fields  grant fields of the models the source stores, by `FieldTarget` value
     */
    public function __construct(
        public string $id,
        public string $class,
        public array $capabilities,
        public bool $dynamic,
        ?string $label = null,
        public array $fields = [],
        public ?Volatility $volatility = null,
    ) {
        $this->label = $label ?? $id;
        $this->matrix = $this->declaredMatrix();
    }

    /** @return array<string, CapabilityCell> */
    private function declaredMatrix(): array
    {
        $authority = in_array(ProvidesGrants::class, $this->capabilities, true) || in_array(ProvidesRoleGrants::class, $this->capabilities, true);
        $queries = in_array(FiltersQueries::class, $this->capabilities, true);
        $fenced = in_array(FencesReads::class, $this->capabilities, true);
        $tests = 'tests/Feature/Diagnostics/SourceCapabilitiesTest.php';

        return [
            'scalar' => self::cell('supported', $authority ? 'Grant capability participates in the scalar pipeline; contributions are validated at runtime.' : 'Accepted panel participant; no direct or role grants are declared.', $tests),
            'batch' => self::cell('supported', 'decideMany uses the scalar pipeline; this does not promise a bulk source read.', $tests),
            'exact_visibility' => self::cell($authority && ! $queries ? 'unsupported' : 'conditional', $authority && ! $queries
                ? 'Grant sources require FiltersQueries for exact selection.'
                : 'Selections must return witnesses, not null; automatic roles, policies, closures, scopes and conditions must also admit exact query semantics.', $tests),
            'stable_cache' => self::cell(! $authority || ! $fenced || ($this->volatility !== null && $this->volatility !== Volatility::Stable) ? 'unsupported' : 'conditional',
                'Cross-request permission-set reuse requires grant authority, Stable volatility, FencesReads and a configured cache store; request reuse is separate.', $tests),
            'tenant_inheritance' => self::cell('conditional', 'The panel tenant policy, membership and access scopes govern inheritance; source contributions must preserve panel and tenant boundaries.', 'tests/Feature/Visibility/TenantMembershipVisibilityTest.php'),
            'filament' => self::cell('conditional', 'Requires the Filament plugin and panel configuration; grant editing requires the database writer, and record lists require exact visibility.', 'tests/Feature/Filament/Gate/VisibilityTest.php'),
        ];
    }

    /**
     * @param  'supported'|'unsupported'|'conditional'  $status
     * @return CapabilityCell
     */
    private static function cell(string $status, string $reason, string $test): array
    {
        return ['status' => $status, 'basis' => 'static_declaration', 'reason' => $reason, 'test_reference' => $test];
    }
}
