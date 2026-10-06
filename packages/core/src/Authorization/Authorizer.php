<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Authorization\Pipeline\AccessPipeline;
use AzGuard\Authorization\Pipeline\Stages\AuthorityStage;
use AzGuard\Authorization\Pipeline\Stages\PrepareStage;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\RecursionDetectedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionSet;
use AzGuard\Kernel\Decision\Explanation;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelResolver;
use AzGuard\Sources\Database\DatabaseSource;
use Closure;
use DateTimeImmutable;
use Fiber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;
use UnitEnum;

final class Authorizer
{
    /** @var array<string,true> */
    private array $active = [];

    public function __construct(private readonly PrepareStage $prepare, private readonly AccessPipeline $pipeline, private readonly AuthorityStage $authority, private readonly PanelResolver $resolver, private readonly BatchEvaluation $batch) {}

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

        return $this->batch->evaluate($selected, $actor, Carbon::now('UTC')->toDateTimeImmutable(),
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
    }

    private function operationKey(Panel $panel, AccessRequest $request): string
    {
        return IdentityCodec::compose([$panel->id(), $request->subject()->type(), $request->subject()->id(), $request->permission()->full(), ($request->tenant() ?? TenantRef::global())->key(), ($request->context() ?? AssignmentScopeRef::global())->key(), (string) (Fiber::getCurrent() === null ? 0 : spl_object_id(Fiber::getCurrent()))]);
    }

    public function decide(Panel $panel, AccessRequest $request, ?ActorRef $actor = null): Decision
    {
        return $this->evaluate($panel, $request, $actor)[0];
    }

    public function explain(Panel $panel, AccessRequest $request, ?ActorRef $actor = null): Explanation
    {
        [$decision, $trace, $now] = $this->evaluate($panel, $request, $actor, diagnostic: true);

        return new Explanation($decision, $trace->steps(), $now, $request->subject(), $trace->resource());
    }

    /** @return array{Decision, Trace, DateTimeImmutable} */
    private function evaluate(Panel $panel, AccessRequest $request, ?ActorRef $actor, bool $diagnostic = false): array
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
            $now = Carbon::now('UTC')->toDateTimeImmutable();
            for ($attempt = 0; ; $attempt++) {
                $trace = new Trace($diagnostic || $request->isTraced(), diagnostic: $diagnostic);

                try {
                    [$catalog,$definition,$frame,$denial] = $this->prepare->prepare($panel, $request, $actor, $trace, $now);

                    $decision = $this->pipeline->evaluate($request, $frame, $catalog, $definition, $trace, $denial);
                    $trace->finish();

                    return [$decision, $trace, $now];
                } catch (ReadAttemptChanged $changed) {
                    if ($attempt === 2) {
                        $decision = $this->pipeline->inconsistent($request, $changed->frame, $trace);
                        $trace->finish();

                        return [$decision, $trace, $now];
                    }
                }
            }
        } finally {
            unset($this->active[$key]);
        }
    }
}
