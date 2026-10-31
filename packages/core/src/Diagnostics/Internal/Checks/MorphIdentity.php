<?php

declare(strict_types=1);

namespace AzGuard\Diagnostics\Internal\Checks;

use AzGuard\Contracts\Diagnostics\DoctorCheck;
use AzGuard\Diagnostics\DoctorContext;
use AzGuard\Diagnostics\DoctorFinding;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Kernel\Identity\IdentityCodec;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/** @internal */
final readonly class MorphIdentity implements DoctorCheck
{
    public function __construct(private Repository $config) {}

    public function key(): string
    {
        return 'morph.identity';
    }

    public function run(DoctorContext $context): iterable
    {
        $seen = [];
        foreach ($context->panels() as $panel) {
            $models = [];
            foreach ($panel->subjectModels() as $model) {
                $models[] = [$model, null];
            }
            $tenant = $panel->tenants()->definition();

            if ($tenant !== null) {
                $models[] = [$tenant->model(), $tenant->type()];
            }
            foreach ($panel->scopeDefinitions() as $definition) {
                if ($definition->model() !== null) {
                    $models[] = [$definition->model(), $definition->type()];
                }
            }

            foreach ($models as [$class, $declared]) {
                if (! is_subclass_of($class, Model::class)) {
                    continue;
                }

                try {
                    $alias = (new $class)->getMorphClass();
                } catch (ClassMorphViolationException) {
                    $alias = $class;
                }
                foreach (array_unique(array_filter([$alias, $declared], 'is_string')) as $type) {
                    $scope = 'panel:'.$panel->id();
                    $details = ['model' => $class, 'alias' => $type];

                    if ($type === $class) {
                        if ($context->isProduction()) {
                            $message = 'Model '.$class.' uses its class name as morph identity; register a stable alias and migrate existing identities.';
                            yield $this->config->get('azguard_doctor.strict_morph_identity') === true
                                ? DoctorFinding::error($this->key(), $message, $scope, $details)
                                : DoctorFinding::warning($this->key(), $message, $scope, $details);
                        }

                        continue;
                    }

                    try {
                        IdentityCodec::assertTypeAlias($type);
                    } catch (InvalidIdentityException) {
                        yield DoctorFinding::error($this->key(), 'Model '.$class.' has an invalid identity type '.$type.'.', $scope, $details);

                        continue;
                    }
                    $resolved = Relation::getMorphedModel($type);

                    if (($seen[$type] ?? $class) !== $class || $resolved !== $class) {
                        yield DoctorFinding::error($this->key(), 'Identity alias '.$type.' of '.$class.' collides or does not resolve back to that model.', $scope, $details);
                    }
                    $seen[$type] = $class;
                }
            }
        }
    }
}
