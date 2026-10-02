<?php

declare(strict_types=1);

namespace AzGuard\Panels;

use AzGuard\Exceptions\AmbiguousPanelException;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Exceptions\PanelNotResolvedException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use UnitEnum;

/**
 * The only rule that picks a panel; every entry point of the package asks it instead of choosing a panel itself.
 *
 * 1. Explicit signals — the panel argument, a full name `panel:local`, a name that starts with a registered panel
 *    prefix, a permission enum attached to panels — are all collected and must agree on one panel.
 * 2. Without explicit signals: the panel of the current request, when it accepts the subject.
 * 3. Otherwise the panel of the subject's model: `azguardDefaultPanel()`, the default panel, the only panel.
 * 4. Otherwise `PanelNotResolvedException`; a panel is never guessed.
 *
 * The resolver reads no authentication, request or configuration state and makes no queries.
 *
 * @phpstan-type Resolution array{panel: Panel, key: ?PermissionKey, step: 'explicit'|'current'|'model'}
 */
final class PanelResolver
{
    public const string EXPLICIT = 'explicit';

    public const string CURRENT = 'current';

    public const string MODEL = 'model';

    public function __construct(
        private readonly PanelRegistry $registry,
        private readonly CurrentPanel $current,
    ) {}

    /**
     * @param  UnitEnum|string|null  $permission  an enum case, a local name, a name with a panel prefix or a full name
     * @param  string|null  $panel  id of the panel named by the caller
     * @return Resolution the panel, the permission as a key of that panel and the step that picked the panel
     *
     * @throws ConflictingPanelException when explicit signals name different panels
     * @throws AmbiguousPanelException when the enum belongs to several panels and nothing else names one
     * @throws UnknownPermissionException when the enum is attached to no panel
     * @throws UnknownPanelException when a named panel is not registered
     * @throws PanelNotResolvedException
     * @throws SubjectNotAcceptedException when the picked panel does not accept the subject
     * @throws InvalidPermissionKeyException
     * @throws InvalidPanelIdException
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function resolve(Model|SubjectRef|null $subject = null, UnitEnum|string|null $permission = null, ?string $panel = null): array
    {
        $signals = $this->signals($permission, $panel);

        [$picked, $step] = $signals === []
            ? $this->implicit($subject, $permission)
            : [$this->agreed($signals), self::EXPLICIT];

        if ($subject !== null && ! $picked->accepts($subject)) {
            throw new SubjectNotAcceptedException(
                self::describeSubject($subject).' is not a subject of panel "'.$picked->id().'". Subject models of the panel: '
                .self::listed($picked->subjectModels()).'.',
            );
        }

        return [
            'panel' => $picked,
            'key' => $permission === null ? null : $this->key($picked, $permission),
            'step' => $step,
        ];
    }

    /**
     * The panel that owns a Gate ability, or null when the ability is not a permission of the package.
     *
     * A single word belongs to Laravel policies. A full name of a registered panel and a name with a registered
     * prefix belong to that panel even when the action is unknown. Any other dotted name belongs to the panel the
     * rule would pick without explicit signals, if there is one.
     *
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function owner(string $ability, Model|SubjectRef|null $subject = null): ?Panel
    {
        if (str_contains($ability, ':')) {
            return $this->registry->find(explode(':', $ability, 2)[0]);
        }

        if (! str_contains($ability, '.')) {
            return null;
        }

        return $this->registry->forPrefix(explode('.', $ability, 2)[0]) ?? $this->candidate($subject);
    }

    /**
     * Explicit signals as "what named the panel" => ids of the panels it allows.
     *
     * @return array<string, list<string>>
     *
     * @throws UnknownPanelException
     * @throws UnknownPermissionException
     */
    private function signals(UnitEnum|string|null $permission, ?string $panel): array
    {
        $signals = [];

        if ($panel !== null) {
            $signals['the panel argument "'.$panel.'"'] = [$this->registry->get($panel)->id()];
        }

        if ($permission instanceof UnitEnum) {
            $attached = $this->registry->forEnum($permission::class);

            if ($attached === []) {
                throw new UnknownPermissionException(
                    'Permission enum '.$permission::class.' is not attached to any panel: list it in permissions([...]) of a panel provider.',
                );
            }

            $signals['the enum '.$permission::class] = array_map(static fn (Panel $panel): string => $panel->id(), $attached);

            return $signals;
        }

        if ($permission === null) {
            return $signals;
        }

        if (str_contains($permission, ':')) {
            $signals['the full name "'.$permission.'"'] = [$this->registry->get(explode(':', $permission, 2)[0])->id()];

            return $signals;
        }

        $prefixed = $this->registry->forPrefix(explode('.', $permission, 2)[0]);

        if ($prefixed !== null) {
            $signals['the prefix of "'.$permission.'"'] = [$prefixed->id()];
        }

        return $signals;
    }

    /**
     * @param  non-empty-array<string, list<string>>  $signals
     *
     * @throws ConflictingPanelException
     * @throws AmbiguousPanelException
     */
    private function agreed(array $signals): Panel
    {
        $allowed = array_values(array_intersect(...array_values($signals)));

        if ($allowed === []) {
            $named = [];

            foreach ($signals as $signal => $panels) {
                $named[] = $signal.' names '.self::listed($panels);
            }

            throw new ConflictingPanelException(
                'Explicit panel signals disagree: '.implode('; ', $named).'. No panel is picked by priority: make the signals name one panel.',
            );
        }

        if (count($allowed) > 1) {
            throw new AmbiguousPanelException(
                ucfirst((string) array_key_first($signals)).' is attached to panels '.self::listed($allowed)
                .': name the panel explicitly.',
            );
        }

        return $this->registry->get($allowed[0]);
    }

    /**
     * @return array{0: Panel, 1: 'current'|'model'}
     *
     * @throws PanelNotResolvedException
     */
    private function implicit(Model|SubjectRef|null $subject, UnitEnum|string|null $permission): array
    {
        $current = $this->currentFor($subject);

        if ($current !== null) {
            return [$current, self::CURRENT];
        }

        $ofModel = $this->ofModel($subject);

        if ($ofModel !== null) {
            return [$ofModel, self::MODEL];
        }

        throw new PanelNotResolvedException($this->hint($subject, $permission));
    }

    private function candidate(Model|SubjectRef|null $subject): ?Panel
    {
        return $this->currentFor($subject) ?? $this->ofModel($subject);
    }

    /**
     * The panel of the request; it is skipped when it does not accept the subject.
     */
    private function currentFor(Model|SubjectRef|null $subject): ?Panel
    {
        $current = $this->current->get();

        return $current !== null && ($subject === null || $current->accepts($subject)) ? $current : null;
    }

    private function ofModel(Model|SubjectRef|null $subject): ?Panel
    {
        if ($subject instanceof Model && method_exists($subject, 'azguardDefaultPanel')) {
            $id = $subject->azguardDefaultPanel();
            $own = is_string($id) ? $this->registry->find($id) : null;

            if ($own !== null && $own->accepts($subject)) {
                return $own;
            }
        }

        $model = $this->modelOf($subject);

        return $model === null ? null : $this->registry->defaultFor($model);
    }

    /**
     * @return class-string<Model>|null
     */
    private function modelOf(Model|SubjectRef|null $subject): ?string
    {
        if ($subject instanceof Model) {
            return $subject::class;
        }

        $model = $subject === null ? null : Relation::getMorphedModel($subject->type());

        return is_string($model) && is_subclass_of($model, Model::class) ? $model : null;
    }

    /**
     * The permission as a key of the picked panel: the panel prefix is stripped, an enum gives its value.
     *
     * @throws InvalidPermissionKeyException
     * @throws InvalidPanelIdException
     * @throws UnknownPermissionException
     */
    private function key(Panel $panel, UnitEnum|string $permission): PermissionKey
    {
        if ($permission instanceof UnitEnum) {
            if (! $permission instanceof BackedEnum || ! is_string($permission->value)) {
                throw new UnknownPermissionException('Permission enum '.$permission::class.' must be string-backed.');
            }

            return PermissionKey::of($panel->id(), $permission->value);
        }

        if (str_contains($permission, ':')) {
            return PermissionKey::parse($permission);
        }

        $prefix = $panel->prefix();

        if ($prefix !== null && str_starts_with($permission, $prefix.'.')) {
            $permission = substr($permission, strlen($prefix) + 1);
        }

        return PermissionKey::of($panel->id(), $permission);
    }

    private function hint(Model|SubjectRef|null $subject, UnitEnum|string|null $permission): string
    {
        $model = $this->modelOf($subject);
        $accepting = $model === null ? [] : array_map(static fn (Panel $panel): string => $panel->id(), $this->registry->forModel($model));

        $what = match (true) {
            $permission instanceof UnitEnum => ' for '.$permission::class,
            is_string($permission) => ' for "'.$permission.'"',
            default => '',
        };

        $panels = match (true) {
            $subject === null => 'There is no subject to take a default panel from.',
            $accepting === [] => 'No panel accepts '.self::describeSubject($subject).'.',
            default => 'Panels that accept '.self::describeSubject($subject).': '.self::listed($accepting).'; none of them is the default.',
        };

        return 'No panel is resolved'.$what.': nothing names a panel and the request has no current panel. '.$panels
            .' Name the panel (the panel argument, a full name such as "admin:orders.view" or a panel prefix),'
            .' or declare default() on the panel of the model.';
    }

    private static function describeSubject(Model|SubjectRef $subject): string
    {
        return $subject instanceof Model ? $subject::class : 'subject "'.$subject->key().'"';
    }

    /**
     * @param  list<string>  $values
     */
    private static function listed(array $values): string
    {
        return $values === [] ? 'none' : '"'.implode('", "', $values).'"';
    }
}
