<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Storage\StorageMutation;
use AzGuard\Tests\Fixtures\Changes\Roles\AuditorRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Diagnostics\ColumnRoleGrant;
use AzGuard\Tests\Fixtures\Diagnostics\DoctorWorld;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\TestPanel;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Checks a database source brings for the grants it stores: orphaned role grants (V11), dead grants and decision
 * fields kept in meta. They read stored grants the way the inspection of grants does, and write nothing.
 */

beforeEach(function (): void {
    CrmWorld::seed();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
});

it('passes the stored grants of the CRM stand, which the current code still gives', function (): void {
    CrmWorld::compile();
    $findings = DoctorWorld::run();

    expect(DoctorWorld::only($findings, 'roles.orphaned'))->toBe([])
        ->and(DoctorWorld::only($findings, 'grants.dead'))->toBe([]);
});

it('V11 a grant of a role the PHP catalog no longer has neither throws nor grants and doctor shows it as a warning', function (): void {
    $panel = CrmWorld::compile();
    CrmWorld::clear();
    CrmWorld::assign('retired', 1, 1);
    $versionBefore = CrmWorld::storage()->state('crm')?->version;

    CrmWorld::assertDecision(CrmWorld::decide($panel), false, DecisionReason::NotGranted);
    $finding = DoctorWorld::only(DoctorWorld::run(), 'roles.orphaned');

    expect(DoctorWorld::summary($finding))->toBe(['panel:crm roles.orphaned warning'])
        ->and($finding[0]->details)->toBe(['grants' => 1, 'origin' => 'manual', 'role' => 'retired', 'tenant' => 'crm.organization:1'])
        ->and($finding[0]->message)->toContain('can be cleaned up')
        ->and(CrmWorld::storage()->state('crm')?->version)->toBe($versionBefore);
});

it('roles.orphaned names the role whose former key old grants still use', function (): void {
    CrmWorld::compile(static fn (PanelBuilder $panel) => $panel->roles([AuditorRole::class]));
    CrmWorld::assign('inspector', 2, 0, scope: CrmWorld::scope(1));

    $finding = DoctorWorld::only(DoctorWorld::run(), 'roles.orphaned');
    expect(DoctorWorld::summary($finding))->toBe(['panel:crm roles.orphaned warning'])
        ->and($finding[0]->message)->toContain('use a former key of "auditor": migrate them to the current key.');
});

it('roles.orphaned fails grants of a known role stored where the role cannot apply', function (): void {
    CrmWorld::compile();
    CrmWorld::assign('seller', 2, 0, scope: CrmWorld::scope(1));

    $finding = DoctorWorld::only(DoctorWorld::run(), 'roles.orphaned');
    expect(DoctorWorld::summary($finding))->toBe(['panel:crm roles.orphaned error'])
        ->and($finding[0]->details['role'])->toBe('seller');
});

it('grants.dead warns about permission grants outside the catalog or decided by a policy, and grants of subjects the panel does not accept', function (): void {
    CrmWorld::compile();
    CrmWorld::assign('clients.ghost', 1, 1, kind: 'permission');
    CrmWorld::assign('clients.view_own_profile', 1, 1, kind: 'permission');
    CrmWorld::storage()->mutate('crm', static function (StorageMutation $mutation): void {
        $row = (array) $mutation->table('role_grants')->where('panel', 'crm')->first();
        unset($row['id']);
        $mutation->table('role_grants')->insert([...$row, 'subject_type' => 'crm.organization', 'subject_id' => '1']);
        $mutation->touch('crm');
    });

    expect(array_map(static fn ($finding): array => $finding->details, DoctorWorld::only(DoctorWorld::run(['crm']), 'grants.dead')))->toBe([
        ['grants' => 1, 'origin' => 'manual', 'permission' => 'clients.ghost', 'tenant' => 'crm.organization:1'],
        ['grants' => 1, 'origin' => 'manual', 'permission' => 'clients.view_own_profile', 'tenant' => 'crm.organization:1'],
        ['grants' => 1, 'kind' => 'role', 'subject_type' => 'crm.organization'],
    ]);
});

it('fields.meta warns about decision fields kept in meta and passes decision fields kept in columns', function (): void {
    CrmWorld::compile();
    expect(array_map(static fn ($finding): string => $finding->details['kind'].' '.$finding->details['field'], DoctorWorld::only(DoctorWorld::run(['crm']), 'fields.meta')))
        ->toBe(['permission_grant eligible', 'role_grant eligible', 'permission_grant region', 'role_grant region']);

    Schema::table('azg_role_grants', static fn (Blueprint $table) => $table->string('region')->nullable());
    DoctorWorld::panels([TestPanel::class => static fn (PanelBuilder $panel) => $panel->for(User::class)->permissions([OrderPermission::class,
        DatabaseSource::make()->rolesOnly()->models(roleGrant: ColumnRoleGrant::class)->decisionFields(roleGrant: ['region'])])]);
    expect(DoctorWorld::only(DoctorWorld::run(), 'fields.meta'))->toBe([]);
});
