<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Pipeline\Stages;

use AzGuard\Authorization\BatchInputs;
use AzGuard\Authorization\Cache\PermissionSetCache;
use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Authorization\ModelSubjectResolver;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Authorization\ReadAttempt;
use AzGuard\Authorization\ReadAttemptChanged;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\PanelSources;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Throwable;

final readonly class PrepareStage
{
    public function __construct(private PanelRegistry $registry, private ModelSubjectResolver $resolver, private Container $container, private BoundaryStage $boundary) {}

    /** @return array{PanelCatalog,PermissionDefinition,EvaluationFrame,?Decision} */
    public function prepare(Panel $panel, AccessRequest $request, ?ActorRef $actor, Trace $trace, ?DateTimeImmutable $now = null): array
    {
        return $this->prepareRequest($panel, $request, $actor, $trace, $now);
    }

    /** @return array{AccessRequest,PanelCatalog,EvaluationFrame,?Decision} */
    public function prepareSuperAdmin(Panel $panel, SubjectRef $subject, AccessScope $scope, Trace $trace, DateTimeImmutable $now): array
    {
        $request = AccessRequest::for($subject, PermissionKey::of($panel->id(), 'superadmin.qualify'))->inScope($scope);
        [$catalog, , $frame, $denial] = $this->prepareRequest($panel, $request, null, $trace, $now, qualificationOnly: true);

        if ($denial === null) {
            try {
                $frame = $frame->withReadAttempt($this->attempt($catalog, $frame, null, $subject));
            } catch (Throwable $error) {
                $trace->error('prepare', 'source_error', 'sources', $error);
                $denial = Decision::deny(DecisionReason::SourceError, $frame->state(), $frame->scope(), 'sources');
            }
        }

        return [$request, $catalog, $frame, $denial];
    }

    /** @return array{PanelCatalog,PermissionDefinition,EvaluationFrame,?Decision} */
    private function prepareRequest(Panel $panel, AccessRequest $request, ?ActorRef $actor, Trace $trace, ?DateTimeImmutable $now = null, bool $qualificationOnly = false): array
    {
        [$catalog, $definition, $frame, $denial] = $this->inputs($panel, $request, $actor, $trace, $now, qualificationOnly: $qualificationOnly);

        return $this->complete($request, $catalog, $definition, $frame, $denial, $trace);
    }

    /** @return array{PanelCatalog,?PermissionDefinition,EvaluationFrame,?Decision} */
    public function inputs(Panel $panel, AccessRequest $request, ?ActorRef $actor, Trace $trace, ?DateTimeImmutable $now = null, ?BatchInputs $batch = null, bool $qualificationOnly = false): array
    {
        if (! $panel->accepts($request->subject())) {
            throw new SubjectNotAcceptedException('Panel '.$panel->id().' does not accept subject '.$request->subject()->type().'.');
        }
        $catalog = $this->registry->catalog($panel->id());
        $definition = $qualificationOnly ? new PermissionDefinition('superadmin.qualify', PermissionAuthority::Grants) : $catalog->find($request->permission());

        if ($definition === null && ! $catalog->isDynamic()) {
            $definition = $catalog->get($request->permission());
        }
        $actor ??= ActorRef::of($request->subject()->type(), $request->subject()->id());
        $now ??= Carbon::now('UTC')->toDateTimeImmutable();
        $subject = $actorModel = null;
        $error = null;

        try {
            $subject = $batch === null ? $this->resolver->resolve($panel, $request->subject()) : $batch->subject($panel, $request->subject());
            $actorModel = $actor->id === null ? null : ($actor->type === $request->subject()->type() && $actor->id === $request->subject()->id() ? $subject
                : ($batch === null ? $this->resolver->resolve($panel, SubjectRef::of($actor->type, $actor->id)) : $batch->subject($panel, SubjectRef::of($actor->type, $actor->id))));
        } catch (Throwable $caught) {
            $error = $caught;
            $trace->error('prepare', 'source_error', ModelSubjectResolver::class, $caught);
        }
        $frame = new EvaluationFrame(
            selectedPanel: $panel, selectedScope: AccessScope::in($request->tenant() ?? TenantRef::global(), $request->context()),
            token: CodeStateToken::of($panel->id(), $this->registry->buildId(), $this->registry->fingerprint($panel->id())),
            decisionNow: $now, selectedActor: $actor, subject: $subject, actorSubject: $actorModel, selectedResource: $request->resource(), batchInputs: $batch,
        );

        [$frame, $boundaryDenial] = $this->boundary->resolve(request: $request, frame: $frame, trace: $trace, structural: false);

        return [$catalog, $definition, $frame, $boundaryDenial ?? ($error === null ? null : Decision::deny(DecisionReason::SourceError, $frame->state(), $frame->scope(), ModelSubjectResolver::class))];
    }

    /** @return array{PanelCatalog,PermissionDefinition,EvaluationFrame,?Decision} */
    public function complete(AccessRequest $request, PanelCatalog $catalog, ?PermissionDefinition $definition, EvaluationFrame $frame, ?Decision $denial, Trace $trace, ?BatchInputs $batch = null): array
    {
        if ($denial !== null && $denial->component !== ModelSubjectResolver::class) {
            return [$catalog, $definition ?? new PermissionDefinition($request->permission()->local(), PermissionAuthority::Grants), $frame, $denial];
        }
        [$frame, $boundaryDenial] = $this->boundary->structural($request, $frame, $trace, $batch);

        if ($boundaryDenial !== null || $denial !== null) {
            return [$catalog, $definition ?? new PermissionDefinition($request->permission()->local(), PermissionAuthority::Grants), $frame, $boundaryDenial ?? $denial];
        }
        $panel = $frame->panel();
        $error = null;

        if ($definition === null && $catalog->isDynamic()) {
            $attempt = $this->attempt($catalog, $frame, $batch, $request->subject());
            $frame = $frame->withReadAttempt($attempt);

            try {
                $catalog = $attempt->catalog();
                $frame = $frame->withDynamicRead();

                if (! $catalog->has($request->permission())) {
                    $attempt->confirm($frame);
                }
            } catch (ReadAttemptChanged $caught) {
                throw $caught;
            } catch (Throwable $caught) {
                try {
                    $attempt->confirm($frame);
                } catch (ReadAttemptChanged $changed) {
                    throw $changed;
                } catch (Throwable $stateError) {
                    $caught = $stateError;
                }
                $reason = DecisionReason::SourceError;
                $trace->error('prepare', $reason->value, 'dynamic_sources', $caught);

                return [$catalog, new PermissionDefinition($request->permission()->local(), PermissionAuthority::Grants), $frame,
                    Decision::deny($reason, $frame->state(), $frame->scope(), 'dynamic_sources')];
            }
            $definition = $catalog->get($request->permission());

        }

        if ($definition?->authority === PermissionAuthority::Grants && $frame->readAttempt === null) {
            try {
                $frame = $frame->withReadAttempt($this->attempt($catalog, $frame, $batch, $request->subject()));
            } catch (Throwable $caught) {
                $error = $caught;
                $trace->error('prepare', 'source_error', 'sources', $caught);
            }
        }

        return [$catalog, $definition ?? $catalog->get($request->permission()), $frame, $error === null ? null : Decision::deny(DecisionReason::SourceError, $frame->state(), $frame->scope(), ModelSubjectResolver::class)];
    }

    private function attempt(PanelCatalog $catalog, EvaluationFrame $frame, ?BatchInputs $batch, SubjectRef $subject): ReadAttempt
    {
        $sources = PanelSources::of($this->registry->recipe($frame->panel()->id()), $this->container)->all();
        $cache = $this->container->make(PermissionSetCache::class);

        return $batch === null ? new ReadAttempt($catalog, $sources, $frame, $cache) : $batch->attempt($catalog, $sources, $frame, $cache, $subject);
    }
}
