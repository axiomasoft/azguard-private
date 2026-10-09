<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Exceptions\DefaultPanelConflictException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelCompiler;
use AzGuard\Panels\PanelProvider;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\Database\DatabaseSource;
use Illuminate\Database\Eloquent\Model;

/**
 * `panels.valid`: the id and the provider of each panel, its subject models and their morph aliases, the storage and models of its database
 * sources, and one default panel per model across all panels.
 *
 * @internal
 */
final readonly class PanelsValid implements DoctorCheck
{
    public function __construct(private PanelRegistry $registry) {}

    public function key(): string
    {
        return 'panels.valid';
    }

    public function run(DoctorContext $context): iterable
    {
        foreach ($context->panels() as $panel) {
            yield from self::check($panel, $this->registry->recipe($panel->id())->providerClass());
        }

        yield from self::defaults($this->registry->all());
    }

    /** @return list<DoctorFinding> */
    public static function check(Panel $panel, ?string $provider): array
    {
        $scope = 'panel:'.$panel->id();
        $findings = [];

        if (! PermissionGrammar::isPanelId($panel->id())) {
            $findings[] = DoctorFinding::error('panels.valid', 'Panel id "'.$panel->id().'" is malformed.', $scope);
        }

        if ($provider === null || ! is_subclass_of($provider, PanelProvider::class) || $provider::getId() !== $panel->id()) {
            $findings[] = DoctorFinding::error('panels.valid', 'Panel '.$panel->id().' has no panel provider that registers it.', $scope,
                ['provider' => $provider ?? '']);
        }

        foreach ($panel->subjectModels() as $model) {
            if (! is_subclass_of($model, Model::class)) {
                $findings[] = DoctorFinding::error('panels.valid', 'Panel '.$panel->id().' accepts '.$model.', which is not an Eloquent model.', $scope, ['model' => $model]);

                continue;
            }

            try {
                IdentityCodec::assertTypeAlias((new $model)->getMorphClass());
            } catch (InvalidIdentityException $error) {
                // Every check and grant of this model would fail on the same identity error.
                $findings[] = DoctorFinding::error('panels.valid', 'Panel '.$panel->id().' accepts '.$model.' without a morph alias: '.$error->getMessage(),
                    $scope, ['model' => $model]);
            }
        }

        foreach ($panel->attachedSources() as $source) {
            if (! $source instanceof DatabaseSource) {
                continue;
            }

            try {
                $source->validateStorage();
            } catch (AzGuardException $error) {
                $findings[] = DoctorFinding::error('panels.valid', 'The database source of panel '.$panel->id().' does not fit its storage: '.$error->getMessage(),
                    $scope, ['code' => $error->code()]);
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, Panel>  $panels  all registered panels by id
     * @return list<DoctorFinding>
     */
    public static function defaults(array $panels): array
    {
        try {
            (new PanelCompiler)->assertDefaults($panels);
        } catch (DefaultPanelConflictException $error) {
            return [DoctorFinding::error('panels.valid', $error->getMessage(), details: ['code' => $error->code()])];
        }

        return [];
    }
}
