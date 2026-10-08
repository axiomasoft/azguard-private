<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Authorization\Pipeline\AccessPipeline;
use AzGuard\Authorization\Pipeline\Stages\AuthorityStage;
use AzGuard\Authorization\Pipeline\Stages\PrepareStage;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Events\AccessDecided;
use AzGuard\Events\EventObservers;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\RecursionDetectedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionSet;
use AzGuard\Kernel\Decision\Explanation;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Decision\RoleContribution;
use AzGuard\Kernel\Grammar\PatternMatcher;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Kernel\Permissions\PermissionSet;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelResolver;
use AzGuard\Scopes\MembershipRestriction;
use AzGuard\Sources\Database\DatabaseSource;
use Closure;
use DateTimeImmutable;
use Fiber;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

/** @phpstan-import-type CompiledRole from \AzGuard\Catalog\RoleCompiler */
final class Authorizer
{
    /** @var array<string,true> */
    private array $active = [];

    public function __construct(private readonly PrepareStage $prepare, private readonly AccessPipeline $pipeline, private readonly AuthorityStage $authority, private readonly PanelResolver $resolver, private readonly BatchEvaluation $batch, private readonly MembershipRestriction $membership) {}

    /** @internal Joint host work on the panel's authority connection.
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withinAuthorityTransaction(Panel $panel, Closure $callback): mixed
    {
        $writer = $panel->writer();

        if (! $writer instanceof DatabaseSource) {
            throw InvalidConfigurationException::failing('authority_transaction', 'Joint authority work requires a DatabaseSource writer.');
        }

        return $writer->withinAuthorityTransaction($panel, $callback);
    }

    public function isSuperAdmin(Panel $panel, SubjectRef $subject, AccessScope $scope): bool
    {
        $key = IdentityCodec::compose([$panel->id(), $subject->type(), $subject->id(), 'superadmin.qualify', $scope->tenant->key(), $scope->context->key(), (string) (Fiber::getCurrent() === null ? 0 : spl_object_id(Fiber::getCurrent()))]);

        if (isset($this->active[$key])) {
            throw new RecursionDetectedException('Recursive super-admin qualification of the same panel, subject and scope.');
        }
        $this->active[$key] = true;

        try {
            $now = Carbon::now('UTC')->toDateTimeImmutable();
            for ($attempt = 0; ; $attempt++) {
                $trace = new Trace(false);
                [$request, $catalog, $frame, $denial] = $this->prepare->prepareSuperAdmin($panel, $subject, $scope, $trace, $now);

                if ($denial !== null) {
                    return false;
                }

                try {
                    [$frame, , $denial] = $this->authority->qualify($request, $frame, $catalog, $trace);
                    $frame = $frame->readAttempt?->confirm($frame) ?? $frame;

                    return $denial === null && $frame->qualifiedSuperAdmin;
                } catch (ReadAttemptChanged) {
                    if ($attempt === 2) {
                        return false;
                    }
                } catch (Throwable $error) {
                    $trace->error('state', 'source_error', 'sources', $error);

                    return false;
                }
            }

        } finally {
            unset($this->active[$key]);
        }
    }

    /**
     * The roles the subject holds in the scope: the role contributions that qualify there, the same way as for
     * `isSuperAdmin()`, each once in the order of the sources. Not a decision: `decide()` stays the answer to a check.
     *
     * @return list<RoleKey>
     */
    public function roles(Panel $panel, SubjectRef $subject, AccessScope $scope): array
    {
        $roles = [];
        foreach ($this->qualified($panel, $subject, $scope)[0] as [$item]) {
            if ($item instanceof RoleContribution) {
                $roles[$item->role->full()] ??= $item->role;
            }
        }

        return array_values($roles);
    }

    /**
     * The Grants permissions of the catalog of the tenant that the qualified contributions in the scope assign:
     * roles expanded by their code definitions, direct grants and grants to all; a super-admin role assigns every
     * Grants permission. PolicyOnly permissions are never in the set. Names are in byte order; the set is valid until
     * the earliest expiry of a contribution that adds to it. Not a decision: restrictions, policies and membership are applied by `decide()`.
     */
    public function permissionSet(Panel $panel, SubjectRef $subject, AccessScope $scope): PermissionSet
    {
        [$qualified, $catalog] = $this->qualified($panel, $subject, $scope);
        $grants = [];
        foreach ($catalog->all() as $local => $definition) {
            if ($definition->authority === PermissionAuthority::Grants) {
                $grants[] = (string) $local;
            }
        }
        $held = [];
        $until = null;
        foreach ($qualified as [$item, $role]) {
            $everything = $item instanceof RoleContribution && $role !== null && $role['super_admin'];
            $patterns = $item instanceof Grant ? [$item->pattern->local()] : ($role['permissions'] ?? []);
            $adds = false;
            foreach ($grants as $local) {
                $covered = $everything;
                foreach ($covered ? [] : $patterns as $pattern) {
                    $covered = $covered || PatternMatcher::covers($pattern, $local);
                }

                if ($covered) {
                    $held[$local] = $adds = true;
                }
            }

            if ($adds && $item->expiresAt !== null && ($until === null || $item->expiresAt < $until)) {
                $until = $item->expiresAt;
            }
        }

        $held = array_keys($held);
        sort($held, SORT_STRING);

        return PermissionSet::of(array_map(static fn (string $local): PermissionPattern => PermissionPattern::of($panel->id(), $local), $held), $until);
    }

    /**
     * Qualified contributions of the subject in the scope through the super-admin preparation and qualification; a
     * refusal of the scope, a source error or a changing read leaves none, as `isSuperAdmin()` answers false.
     *
     * @return array{list<array{Grant|RoleContribution, CompiledRole|null}>, PanelCatalog}
     */
    private function qualified(Panel $panel, SubjectRef $subject, AccessScope $scope): array
    {
        $key = IdentityCodec::compose([$panel->id(), $subject->type(), $subject->id(), 'contributions.qualify', $scope->tenant->key(), $scope->context->key(), (string) (Fiber::getCurrent() === null ? 0 : spl_object_id(Fiber::getCurrent()))]);

        if (isset($this->active[$key])) {
            throw new RecursionDetectedException('Recursive qualification of the same panel, subject and scope.');
        }
        $this->active[$key] = true;

        try {
            $now = Carbon::now('UTC')->toDateTimeImmutable();
            for ($attempt = 0; ; $attempt++) {
                $trace = new Trace(false);
                [$request, $catalog, $frame, $denial] = $this->prepare->prepareSuperAdmin($panel, $subject, $scope, $trace, $now);

                if ($denial !== null) {
                    return [[], $catalog];
                }

                try {
                    if ($catalog->isDynamic() && $frame->readAttempt !== null) {
                        $catalog = $frame->readAttempt->catalog();
                        $frame = $frame->withDynamicRead();
                    }
                    [$frame, $qualified, $denial] = $this->authority->qualifiedContributions($request, $frame, $catalog, $trace);
                    $frame->readAttempt?->confirm($frame);

                    return [$denial === null ? $qualified : [], $catalog];
                } catch (ReadAttemptChanged) {
                    if ($attempt === 2) {
                        return [[], $catalog];
                    }
                } catch (Throwable $error) {
                    $trace->error('state', 'source_error', 'sources', $error);

                    return [[], $catalog];
                }
            }
        } finally {
            unset($this->active[$key]);
        }
    }

    /**
     * @internal The scope a request of the subject takes in the panel: the given one, otherwise the ambient scope of
     * the panel and its tenant and assignment scope resolvers, as for a decision. Null when the scope boundary refuses
     * it, the subject is not a member of its tenant or assignment scope, or an input fails.
     */
    public function admissionScope(Panel $panel, SubjectRef $subject, ?AccessScope $scope = null): ?AccessScope
    {
        $request = AccessRequest::for($subject, PermissionKey::of($panel->id(), 'superadmin.qualify'));
        $request = $scope === null ? $request : $request->inScope($scope);

        try {
            [, , $frame, $denial] = $this->prepare->inputs($panel, $request, null, new Trace(false), qualificationOnly: true);

            return $denial === null && ! $this->membership->check($request, $frame)->denied() ? $frame->scope() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @internal Assignment scopes of one type of the tenant in which a source holds a role assignment of the subject.
     * A prefilter of raw witnesses, never an answer: each scope is qualified again on its own. Null when a source
     * cannot select assignment scopes or fails.
     *
     * @return list<AssignmentScopeRef>|null
     */
    public function roleScopes(Panel $panel, SubjectRef $subject, TenantRef $tenant, string $type): ?array
    {
        $now = Carbon::now('UTC')->toDateTimeImmutable();
        for ($attempt = 0; ; $attempt++) {
            try {
                [$request, , $frame, $denial] = $this->prepare->prepareSuperAdmin($panel, $subject, AccessScope::in($tenant), new Trace(false), $now);

                if ($denial !== null || $frame->readAttempt === null) {
                    return null;
                }
                $scopes = [];
                foreach ($frame->readAttempt->selections($request, $frame, $type) as [, $item]) {
                    if ($item instanceof RoleContribution && ! $item->scope->context->isGlobal()) {
                        $scopes[$item->scope->context->key()] = $item->scope->context;
                    }
                }
                $frame->readAttempt->confirm($frame);

                return array_values($scopes);
            } catch (ReadAttemptChanged) {
                if ($attempt === 2) {
                    return null;
                }
            } catch (Throwable) {
                return null;
            }
        }
    }

    /** @return array{Panel, PermissionKey} */
    public function resolve(Model|SubjectRef $subject, UnitEnum|string $permission, ?string $panel = null): array
    {
        $resolved = $this->resolver->resolve($subject, $permission, $panel);

        return [$resolved['panel'], $resolved['key'] ?? throw InvalidConfigurationException::failing('permission', 'A permission key is required.')];
    }

    /** @param list<AccessRequest> $requests */
    public function decideMany(array $requests, ?ActorRef $actor = null): DecisionSet
    {
        $selected = [];
        foreach ($requests as $request) {
            [$panel] = $this->resolve($request->subject(), $request->permission()->full());
            $key = $this->operationKey($panel, $request);

            if (isset($this->active[$key])) {
                throw new RecursionDetectedException('Recursive authorization of the same panel, subject, permission and scope.');
            }
            $selected[] = [$panel, $request];
        }

        $now = Carbon::now('UTC')->toDateTimeImmutable();
        $set = $this->batch->evaluate($selected, $actor, $now,
            enter: function (Panel $panel, AccessRequest $request): void {
                $key = $this->operationKey($panel, $request);

                if (isset($this->active[$key])) {
                    throw new RecursionDetectedException('Recursive authorization of the same panel, subject, permission and scope.');
                }
                $this->active[$key] = true;
            },
            leave: function (Panel $panel, AccessRequest $request): void {
                unset($this->active[$this->operationKey($panel, $request)]);
            });
        foreach ($selected as $i => [$panel, $request]) {
            $this->trace($panel, $request, $set->get($i), $actor, $now);
        }

        return $set;
    }

    private function operationKey(Panel $panel, AccessRequest $request): string
    {
        return IdentityCodec::compose([$panel->id(), $request->subject()->type(), $request->subject()->id(), $request->permission()->full(), ($request->tenant() ?? TenantRef::global())->key(), ($request->context() ?? AssignmentScopeRef::global())->key(), (string) (Fiber::getCurrent() === null ? 0 : spl_object_id(Fiber::getCurrent()))]);
    }

    public function decide(Panel $panel, AccessRequest $request, ?ActorRef $actor = null): Decision
    {
        return $this->evaluate($panel, $request, $actor)[0];
    }

    /** @template TModel of Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function visibleTo(Panel $panel, Builder $query, Model|SubjectRef|null $subject, UnitEnum|string $permission, ?AccessScope $scope = null): Builder
    {
        return app(Visibility::class)->visibleTo($panel, $query, $subject, $permission, $scope);
    }

    /** @internal Fixed clock for one bounded evaluation. */
    public function decideAt(Panel $panel, AccessRequest $request, DateTimeImmutable $now): Decision
    {
        return $this->evaluate($panel, $request, now: $now)[0];
    }

    public function explain(Panel $panel, AccessRequest $request, ?ActorRef $actor = null): Explanation
    {
        [$decision, $trace, $now] = $this->evaluate($panel, $request, $actor, diagnostic: true);

        return new Explanation($decision, $trace->steps(), $now, $request->subject(), $trace->resource());
    }

    /** @return array{Decision, Trace, DateTimeImmutable} */
    private function evaluate(Panel $panel, AccessRequest $request, ?ActorRef $actor = null, bool $diagnostic = false, ?DateTimeImmutable $now = null): array
    {
        if ($panel->id() !== $request->permission()->panel()) {
            throw new ConflictingPanelException('The selected panel differs from the request permission panel.');
        }
        $key = $this->operationKey($panel, $request);

        if (isset($this->active[$key])) {
            throw new RecursionDetectedException('Recursive authorization of the same panel, subject, permission and scope.');
        }
        $this->active[$key] = true;

        try {
            $now ??= Carbon::now('UTC')->toDateTimeImmutable();
            for ($attempt = 0; ; $attempt++) {
                $trace = new Trace($diagnostic || $request->isTraced(), diagnostic: $diagnostic);

                try {
                    [$catalog,$definition,$frame,$denial] = $this->prepare->prepare($panel, $request, $actor, $trace, $now);

                    $decision = $this->pipeline->evaluate($request, $frame, $catalog, $definition, $trace, $denial);
                    $trace->finish();
                    $this->trace($panel, $request, $decision, $actor, $now);

                    return [$decision, $trace, $now];
                } catch (ReadAttemptChanged $changed) {
                    if ($attempt === 2) {
                        $decision = $this->pipeline->inconsistent($request, $changed->frame, $trace);
                        $trace->finish();
                        $this->trace($panel, $request, $decision, $actor, $now);

                        return [$decision, $trace, $now];
                    }
                }
            }
        } finally {
            unset($this->active[$key]);
        }
    }

    /**
     * Publishes the decision when the panel traces decisions; otherwise nothing is built or dispatched. A decision
     * made inside a transaction the package holds open waits for its commit; a failing listener is reported and the
     * decision stands. The test kit watches the decisions of a panel that does not trace: it receives the event and the
     * dispatcher does not.
     */
    private function trace(Panel $panel, AccessRequest $request, Decision $decision, ?ActorRef $actor, DateTimeImmutable $now): void
    {
        $observers = app(EventObservers::class);
        $traced = $panel->settings()->traceDecisions();

        if (! $traced && ! $observers->active()) {
            return;
        }
        $event = new AccessDecided(
            eventId: strtolower((string) Str::ulid()), occurredAt: $now, panel: $panel->id(), tenant: $decision->scope->tenant, actor: $actor,
            correlationId: strtolower((string) Str::ulid()), state: $decision->state, subject: $request->subject(), permission: $request->permission(),
            context: $decision->scope->context, effect: $decision->effect, reason: $decision->reason, component: $decision->component,
        );
        $deliver = static function () use ($event, $observers, $traced): void {
            $observers->observe($event);

            if (! $traced) {
                return;
            }

            try {
                app(Dispatcher::class)->dispatch($event);
            } catch (Throwable $error) {
                report($error);
            }
        };
        $writer = $panel->writer();

        if (! $writer instanceof DatabaseSource || ! $writer->afterAuthorityCommit($deliver)) {
            $deliver();
        }
    }
}
