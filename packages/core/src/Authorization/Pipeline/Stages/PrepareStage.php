<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Pipeline\Stages;

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
                $frame = $frame->withReadAttempt(new ReadAttempt($catalog, PanelSources::of($this->registry->recipe($panel->id()), $this->container)->all(), $frame, $this->container->make(PermissionSetCache::class)));
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
            $subject = $this->resolver->resolve($panel, $request->subject());
            $actorModel = $actor->id === null ? null : ($actor->type === $request->subject()->type() && $actor->id === $request->subject()->id() ? $subject : $this->resolver->resolve($panel, SubjectRef::of($actor->type, $actor->id)));
        } catch (Throwable $caught) {
            $error = $caught;
            $trace->error('prepare', 'source_error', ModelSubjectResolver::class, $caught);
        }
        $frame = new EvaluationFrame(
            selectedPanel: $panel, selectedScope: AccessScope::in($request->tenant() ?? TenantRef::global(), $request->context()),
            token: CodeStateToken::of($panel->id(), $this->registry->buildId(), $this->registry->fingerprint($panel->id())),
            decisionNow: $now, selectedActor: $actor, subject: $subject, actorSubject: $actorModel, selectedResource: $request->resource(),
        );

        [$frame, $boundaryDenial] = $this->boundary->resolve(request: $request, frame: $frame, trace: $trace);

        if ($boundaryDenial !== null) {
            return [$catalog, $definition ?? new PermissionDefinition($request->permission()->local(), PermissionAuthority::Grants), $frame, $boundaryDenial];
        }

        if ($definition === null && $catalog->isDynamic()) {
            if ($error === null) {
                $attempt = new ReadAttempt($catalog, PanelSources::of($this->registry->recipe($panel->id()), $this->container)->all(), $frame, $this->container->make(PermissionSetCache::class));
                $frame = $frame->withReadAttempt($attempt);

                try {
                    $catalog = $attempt->catalog();

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
            } else {
                $definition = new PermissionDefinition($request->permission()->local(), PermissionAuthority::Grants);
            }
        }

        if ($error === null && $definition?->authority === PermissionAuthority::Grants && $frame->readAttempt === null) {
            try {
                $frame = $frame->withReadAttempt(new ReadAttempt($catalog, PanelSources::of($this->registry->recipe($panel->id()), $this->container)->all(), $frame, $this->container->make(PermissionSetCache::class)));
            } catch (Throwable $caught) {
                $error = $caught;
                $trace->error('prepare', 'source_error', 'sources', $caught);
            }
        }

        return [$catalog, $definition ?? $catalog->get($request->permission()), $frame, $error === null ? null : Decision::deny(DecisionReason::SourceError, $frame->state(), $frame->scope(), ModelSubjectResolver::class)];
    }
}
