<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Sources\Database;

use AzGuard\Authorization\Authorizer;
use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\Reads;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Storage\Storage;
use AzGuard\Storage\StorageMutation;
use AzGuard\Storage\StorageRegistry;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\User;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;

final class DatabaseWorld
{
    /** @return array{Panel, EvaluationFrame, PanelRegistry} */
    public static function compile(Source $source, ?AccessScope $scope = null, Reads $reads = Reads::Primary): array
    {
        [,,$registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
            ->for(User::class)->resourcePrefix(false)->permissions([DatabasePermission::class, $source])
            ->roles([EditorRole::class])->policies([PolicyBinding::for(DatabasePermission::Policy, DatabasePolicy::class)])->consistency($reads)]);
        app()->instance(PanelRegistry::class, $registry);
        app()->forgetInstance(Authorizer::class);
        $panel = $registry->get('admin');
        $frame = new EvaluationFrame(
            selectedPanel: $panel,
            selectedScope: $scope ?? AccessScope::in(TenantRef::global()),
            token: CodeStateToken::of('admin', $registry->buildId(), $registry->fingerprint('admin')),
            decisionNow: new DateTimeImmutable('2040-01-01T12:00:00+00:00'),
            selectedActor: ActorRef::of('user', '1'),
        );

        return [$panel, $frame, $registry];
    }

    public static function storage(): Storage
    {
        return app(StorageRegistry::class)->get('default');
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function row(string $kind = 'role', ?AccessScope $scope = null, array $overrides = []): array
    {
        $scope ??= AccessScope::in(TenantRef::global());

        return array_replace([
            'panel' => 'admin', 'tenant_key' => $scope->tenant->key(),
            'tenant_type' => $scope->tenant->type(), 'tenant_id' => $scope->tenant->id(),
            'subject_type' => 'user', 'subject_id' => '1',
            'context_key' => $scope->context->key(), 'context_type' => $scope->context->type(), 'context_id' => $scope->context->id(),
            $kind => $kind === 'role' ? 'editor' : 'documents.view', 'origin' => 'manual',
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $rows */
    public static function insert(string $kind, array $rows): void
    {
        self::storage()->mutate('admin', static function (StorageMutation $mutation) use ($kind, $rows): void {
            foreach ($rows as $row) {
                $mutation->table($kind.'_grants')->insert($row);
            }
            $mutation->touch('admin');
        });
    }

    /** @template T
     * @param  iterable<T>  $items
     * @return list<T>
     */
    public static function items(iterable $items): array
    {
        return is_array($items) ? array_values($items) : array_values(iterator_to_array($items));
    }

    public static function seedSubject(): void
    {
        Relation::morphMap(['user' => User::class], false);
        $schema = self::storage()->connection()->getSchemaBuilder();
        $schema->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('department')->nullable();
            $table->boolean('is_root')->default(false);
        });
        User::query()->insert(['id' => 1]);
    }

    public static function request(DatabasePermission $permission = DatabasePermission::View): AccessRequest
    {
        return AccessRequest::for(SubjectRef::of('user', 1), PermissionKey::of('admin', $permission->value));
    }
}
