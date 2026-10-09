<?php

declare(strict_types=1);

namespace AzGuard\Directories;

use AzGuard\Contracts\Subjects\SubjectDirectory;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Scopes\ModelIdentity;
use Illuminate\Database\Eloquent\Model;

/**
 * Default subject directory over the models a panel declares with `for(model:)`.
 *
 * It searches by key and by the columns a model lists in `azguardSearchColumns()`, and labels a subject with
 * `azguardLabel()` or its key. It does not depend on the target of the lookup, so it works while the subject is
 * still being chosen.
 *
 * @api
 */
final readonly class ModelSubjectDirectory implements SubjectDirectory
{
    /** @param list<class-string<Model>> $models */
    public function __construct(private array $models) {}

    /**
     * @return list<SubjectOption>
     */
    public function search(string $term, LookupContext $lookup, int $limit, ?string $type = null): array
    {
        $options = [];

        foreach ($this->models as $model) {
            $alias = (new $model)->getMorphClass();

            if ($limit - count($options) < 1) {
                break;
            }

            if ($type !== null && $type !== $alias) {
                continue;
            }
            $query = $model::query();
            ModelLookup::match($query, $term);

            foreach ($query->orderBy($query->getModel()->getQualifiedKeyName())->limit($limit - count($options))->get() as $record) {
                $options[] = new SubjectOption(SubjectRef::of($alias, (string) ModelIdentity::key($record)), ModelLookup::label($record));
            }
        }

        return $options;
    }

    public function describe(SubjectRef $subject, LookupContext $lookup): ?SubjectOption
    {
        foreach ($this->models as $model) {
            if ((new $model)->getMorphClass() !== $subject->type()) {
                continue;
            }
            $record = $model::query()->whereKey($subject->id())->first();

            return $record === null ? null : new SubjectOption($subject, ModelLookup::label($record));
        }

        return null;
    }
}
