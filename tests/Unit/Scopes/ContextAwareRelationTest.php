<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Scopes\ContextAware;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

function contextAwareTask(): Model
{
    return new class extends Model
    {
        use ContextAware;

        public function azguardContextType(): string
        {
            return 'project';
        }

        public function azguardContextRelation(): ?string
        {
            return 'projects';
        }

        /** @return HasMany<Model, $this> */
        public function projects(): HasMany
        {
            return $this->hasMany(self::class);
        }
    };
}

it('reads the context from a loaded to-one relation and none from an empty one', function (): void {
    $task = contextAwareTask();
    $project = new class extends Model {};

    $task->setRelation('projects', $project);
    expect($task->azguardContext())->toBe($project);

    $task->setRelation('projects', null);
    expect($task->azguardContext())->toBeNull();
});

it('rejects a context relation that returns many records', function (): void {
    $task = contextAwareTask();
    $task->setRelation('projects', new Collection);

    expect(fn () => $task->azguardContext())->toThrow(DefinitionException::class, 'must be a to-one relation to the context model');
});
