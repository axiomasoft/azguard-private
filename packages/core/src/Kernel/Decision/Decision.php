<?php

declare(strict_types=1);

namespace AzGuard\Kernel\Decision;

use AzGuard\Exceptions\ConsistencyException;
use AzGuard\Kernel\Identity\AccessScope;

/**
 * The answer to an access request: effect, reason consistent with it, the state it was made against and the scope.
 */
final readonly class Decision
{
    private const array ALLOW_REASONS = [DecisionReason::Granted, DecisionReason::SuperAdmin, DecisionReason::Policy];

    /**
     * @param  list<Grant>  $grants
     */
    private function __construct(
        public Effect $effect,
        public DecisionReason $reason,
        public CodeStateToken|StateToken $state,
        public AccessScope $scope,
        public ?string $component,
        public array $grants,
    ) {}

    /**
     * @param  list<Grant>  $grants  grants that gave the permission, when traced
     *
     * @throws ConsistencyException when the reason cannot allow
     */
    public static function allow(
        DecisionReason $reason,
        CodeStateToken|StateToken $state,
        AccessScope $scope,
        ?string $component = null,
        array $grants = [],
    ): self {
        return self::make(Effect::Allow, $reason, $state, $scope, $component, $grants);
    }

    /**
     * @throws ConsistencyException when the reason cannot deny
     */
    public static function deny(
        DecisionReason $reason,
        CodeStateToken|StateToken $state,
        AccessScope $scope,
        ?string $component = null,
    ): self {
        return self::make(Effect::Deny, $reason, $state, $scope, $component, []);
    }

    public static function notApplicable(CodeStateToken|StateToken $state, AccessScope $scope, ?string $component = null): self
    {
        return self::make(Effect::NotApplicable, DecisionReason::NotApplicable, $state, $scope, $component, []);
    }

    /**
     * Whether the effect admits the reason: Allow only for granted, super-admin or policy; NotApplicable only with
     * its own reason; Deny for any reason that neither allows nor means "not applicable".
     */
    public static function admits(Effect $effect, DecisionReason $reason): bool
    {
        return match ($effect) {
            Effect::Allow => in_array($reason, self::ALLOW_REASONS, true),
            Effect::NotApplicable => $reason === DecisionReason::NotApplicable,
            Effect::Deny => $reason !== DecisionReason::NotApplicable && ! in_array($reason, [DecisionReason::Granted, DecisionReason::SuperAdmin], true),
        };
    }

    public function allowed(): bool
    {
        return $this->effect === Effect::Allow;
    }

    /**
     * @param  list<Grant>  $grants
     */
    private static function make(
        Effect $effect,
        DecisionReason $reason,
        CodeStateToken|StateToken $state,
        AccessScope $scope,
        ?string $component,
        array $grants,
    ): self {
        if (! self::admits($effect, $reason)) {
            throw new ConsistencyException(sprintf(
                'Decision effect "%s" cannot have reason "%s".',
                $effect->value,
                $reason->value,
            ));
        }

        return new self($effect, $reason, $state, $scope, $component, $grants);
    }
}
