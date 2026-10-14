<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Parity;

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Changes\Internal\ChangePipeline;
use AzGuard\Context\AssignmentScopePhase;
use AzGuard\Directories\LookupContext;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Exceptions\InvalidChangeFieldsException;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Tests\Fixtures\Changes\Roles\AuditorRole;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\AnalystRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\CallerRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\SellerRole;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Roles\TenantAdminRole;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Carbon;

/**
 * Runs the five paths of the matrix over the compiled world of one module state (`ParityWorld::compile()`); `$module`
 * suffixes every path name, so module on/off is compared like any other path.
 *
 * Decision paths, per (panel, subject, permission, client, tenant):
 * - `scalar`: `Authorizer::decide()` on the client in the tenant;
 * - `batch`: one `decideMany()` over every case of the run, the verdict at the case's index;
 * - `sql`: membership of the client in `visibleTo()` of (panel, subject, permission, tenant);
 *   `VisibilityNotSupportedException` is `Unsupported` with its stable reason.
 *
 * Assignment paths, per (panel, target, role, tenant, project|tenant-wide, expiry):
 * - `directory`: the project is offered by the panel's assignment-scope directory in the Assignment phase for the
 *   target and the role (`PanelDirectories::scopes()->search()`), the only eligibility query;
 * - `write`: the final validation of `ChangePipeline::grant()` accepts the proposed grant (applied or unchanged),
 *   inside a host transaction the runner rolls back, so the world never changes.
 *
 * The directory always runs. Its answer is `Unsupported` only where its contract has no input for what decided the
 * write: a tenant-wide context (it lists projects only), and a past expiry that the write rejects as an invalid
 * `until` field (no `until` in `LookupContext`). Any other rejection, tenant membership included, is compared: the
 * directory documents that it offers only the contexts the final write would accept for the target.
 */
final readonly class ParityRunner
{
    private const array ROLE_CLASSES = ['seller' => SellerRole::class, 'analyst' => AnalystRole::class, 'caller' => CallerRole::class,
        'auditor' => AuditorRole::class, 'tenant-admin' => TenantAdminRole::class];

    public function __construct(private string $module = '') {}

    /**
     * @param  list<ParityCase>  $cases
     * @return array<string, array<string, Verdict|Unsupported>> per case id, per path
     */
    public function decisions(array $cases): array
    {
        $authorizer = app(Authorizer::class);
        $registry = app(PanelRegistry::class);
        $clients = Client::query()->orderBy('id')->get()->keyBy('id');
        $requests = array_map(static fn (ParityCase $case): AccessRequest => AccessRequest::for(SubjectRef::of('crm.user', $case->subject),
            PermissionKey::of($case->panel, $case->key))->inTenant(self::tenant($case->tenant))->on(null, $clients[$case->target]), $cases);
        $batch = $authorizer->decideMany($requests);
        $lists = [];
        $results = [];

        foreach ($cases as $i => $case) {
            $scalar = $authorizer->decide($registry->get($case->panel), $requests[$i]);
            $set = $batch->get($i);
            $list = $lists[$case->panel.'|'.$case->subject.'|'.$case->key.'|'.$case->tenant] ??= $this->visible($case);
            $results[$case->id()] = [
                'scalar'.$this->module => new Verdict($scalar->allowed(), $scalar->reason->value),
                'batch'.$this->module => new Verdict($set->allowed(), $set->reason->value),
                'sql'.$this->module => $list instanceof Unsupported ? $list : new Verdict(in_array($case->target, $list, true)),
            ];
        }

        return $results;
    }

    /**
     * @param  list<ParityCase>  $cases
     * @return array<string, array<string, Verdict|Unsupported>> per case id, per path
     */
    public function assignments(array $cases): array
    {
        $offers = [];
        $results = [];

        foreach ($cases as $case) {
            [$write, $fieldErrors] = $this->admitted($case);
            $offered = in_array($case->target, $offers[$case->panel.'|'.$case->subject.'|'.$case->key.'|'.$case->tenant] ??= $this->offered($case), true);
            $results[$case->id()] = [
                'directory'.$this->module => match (true) {
                    $case->target === null => new Unsupported('directory_lists_no_tenant_wide_context'),
                    $case->until === 'past' && in_array('until', $fieldErrors, true) => new Unsupported('directory_has_no_expiry_input'),
                    default => new Verdict($offered),
                },
                'write'.$this->module => $write,
            ];
        }

        return $results;
    }

    /** @return list<int>|Unsupported the visible client ids of the case's list */
    private function visible(ParityCase $case): array|Unsupported
    {
        $panel = app(PanelRegistry::class)->get($case->panel);

        try {
            $query = app(Authorizer::class)->visibleTo($panel, Client::query(), SubjectRef::of('crm.user', $case->subject), $case->key,
                AccessScope::in(self::tenant($case->tenant)));
        } catch (VisibilityNotSupportedException $error) {
            return new Unsupported($error->reason);
        }

        return array_map(intval(...), $query->orderBy('id')->pluck('id')->all());
    }

    /** @return list<int> the project ids the directory offers for the case's target, role and tenant */
    private function offered(ParityCase $case): array
    {
        $panel = app(PanelRegistry::class)->get($case->panel);
        $lookup = new LookupContext(panel: $panel, scope: AccessScope::in(self::tenant($case->tenant)), actor: null, actorModel: null,
            subject: SubjectRef::of('crm.user', $case->subject), user: User::query()->find($case->subject), role: app(self::ROLE_CLASSES[$case->key]),
            proposed: [], phase: AssignmentScopePhase::Assignment, now: Carbon::now('UTC')->toDateTimeImmutable());

        return array_map(static fn ($option): int => (int) $option->scope->id(),
            AzGuard::panel($case->panel)->directories()->scopes('crm.project')->search('crm.project', '', $lookup, 100));
    }

    /** @return array{Verdict, list<string>} the verdict of final validation and the fields it rejected */
    private function admitted(ParityCase $case): array
    {
        $panel = app(PanelRegistry::class)->get($case->panel);
        $until = ParityWorld::EXPIRY[$case->until];
        $connection = CrmWorld::storage()->connection();
        $connection->beginTransaction();

        try {
            $result = app(ChangePipeline::class)->grant($panel, self::tenant($case->tenant), SubjectRef::of('crm.user', $case->subject),
                RoleKey::of($case->panel, $case->key), $case->target === null ? AssignmentScopeRef::global() : CrmWorld::scope(project: $case->target)->context,
                until: $until === null ? null : new DateTimeImmutable($until.' UTC'));

            return [new Verdict(true, $result->applied() ? 'applied' : 'unchanged'), []];
        } catch (InvalidChangeFieldsException $error) {
            return [new Verdict(false, $error->code().':'.implode(',', array_keys($error->errors()))), array_map(strval(...), array_keys($error->errors()))];
        } catch (AzGuardException $error) {
            return [new Verdict(false, $error->code()), []];
        } finally {
            $connection->rollBack();
        }
    }

    private static function tenant(int $id): TenantRef
    {
        return TenantRef::of('crm.organization', $id);
    }
}
