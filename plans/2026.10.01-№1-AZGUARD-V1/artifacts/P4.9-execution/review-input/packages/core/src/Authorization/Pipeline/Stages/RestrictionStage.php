<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Pipeline\Stages;

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Contracts\Authorization\Restriction;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;

final readonly class RestrictionStage
{
    public function __construct(private Container $container) {}

    /** @return list<Restriction> */
    public function resolve(EvaluationFrame $frame): array
    {
        $resolved = [];
        foreach ($frame->panel()->restrictions() as $declared) {
            $restriction = is_string($declared) ? $this->container->make($declared) : $declared;

            if (! $restriction instanceof Restriction) {
                throw new RuntimeException('Restriction resolver did not return Restriction.');
            }
            $resolved[] = $restriction;
        }

        return $resolved;
    }

    /** @param list<Restriction> $restrictions */
    public function validateKeys(EvaluationFrame $frame, array $restrictions, Trace $trace): ?Decision
    {
        $keys = [];
        foreach ($restrictions as $restriction) {
            try {
                $key = $restriction->key();
            } catch (Throwable $error) {
                $trace->error('restriction', 'restriction_error', $restriction::class, $error);

                return Decision::deny(DecisionReason::RestrictionError, $frame->state(), $frame->scope(), $restriction::class);
            }

            if (isset($keys[$key])) {
                throw new DefinitionException('Panel '.$frame->panel()->id().' has duplicate restriction key '.$key.'.');
            }
            $keys[$key] = true;
        }

        return null;
    }

    /** @param list<Restriction> $restrictions */
    public function decide(AccessRequest $request, EvaluationFrame $frame, array $restrictions, Trace $trace): ?Decision
    {
        foreach ($restrictions as $restriction) {
            $component = $restriction::class;

            try {
                $component = $restriction->key();

                if (! $restriction->appliesTo($request, $frame)) {
                    $trace->record('restriction', 'not_applicable', $component);

                    continue;
                }

                if ($frame->qualifiedSuperAdmin && $restriction->exemptsSuperAdmin()) {
                    $trace->record('restriction', 'exempt', $component);

                    continue;
                }
                $result = $restriction->check($request, $frame);
                $trace->record('restriction', $result->reason() ?? 'pass', $component);

                if ($result->denied()) {
                    return Decision::deny(DecisionReason::Restricted, $frame->state(), $frame->scope(), $component);
                }
            } catch (Throwable $error) {
                $trace->error('restriction', 'restriction_error', $component, $error);

                return Decision::deny(DecisionReason::RestrictionError, $frame->state(), $frame->scope(), $component);
            }
        }

        return null;
    }
}
