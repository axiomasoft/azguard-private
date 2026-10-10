<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Storage;

use AzGuard\Storage\Storage;
use Illuminate\Database\QueryException;

final class SchemaAssertions
{
    /** @return array<string, mixed> */
    public static function grant(string $identity = 'role'): array
    {
        return ['panel' => 'admin', 'tenant_key' => 'global', $identity => $identity === 'role' ? 'editor' : 'posts.view',
            'subject_type' => 'user', 'subject_id' => '1', 'context_key' => 'global'];
    }

    public static function verify(Storage $storage): void
    {
        $schema = $storage->connection()->getSchemaBuilder();
        foreach (['permissions', 'role_grants', 'permission_grants', 'panel_state', 'storage_state'] as $table) {
            expect($schema->hasTable($storage->prefix().$table))->toBeTrue();
        }
        foreach (['roles', 'role_permissions', 'role_contexts'] as $table) {
            expect($schema->hasTable($storage->prefix().$table))->toBeFalse();
        }
        foreach (['role', 'permission'] as $identity) {
            $table = $storage->table($identity.'_grants');
            $grant = self::grant($identity);
            $table->insert($grant);
            $table->insert($grant + ['origin' => 'import']);
            expect(fn () => $table->insert($grant))->toThrow(QueryException::class);
            foreach ([['tenant_type' => 'org'], ['tenant_key' => 'org:1'], ['tenant_key' => 'org:1', 'tenant_type' => 'org'],
                ['context_type' => 'project'], ['context_key' => 'project:1'], ['context_key' => 'project:1', 'context_id' => '1']] as $invalid) {
                expect(fn () => $table->insert(array_replace($grant, ['origin' => 'bad'], $invalid)))->toThrow(QueryException::class);
            }
            expect(fn () => (clone $table)->where('origin', 'manual')->update(['tenant_type' => 'org']))->toThrow(QueryException::class);
            $table->insert(array_replace($grant, ['tenant_key' => 'org:1', 'tenant_type' => 'org', 'tenant_id' => '1',
                'context_key' => 'project:1', 'context_type' => 'project', 'context_id' => '1']));
            $table->insert(array_replace($grant, [$identity => 'Editor']));
            $table->insert(array_replace($grant, [$identity => 'editor ']));
            expect($table->count())->toBe(5);
            $table->insert(array_replace($grant, [$identity => 'future', 'expires_at' => '2045-01-01 00:00:00']));
        }
        $permissions = $storage->table('permissions');
        $permission = ['panel' => 'admin', 'tenant_key' => 'global', 'name' => 'posts.view'];
        $permissions->insert($permission);
        $permissions->insert(array_replace($permission, ['name' => 'Posts.view']));
        $permissions->insert(array_replace($permission, ['name' => 'posts.view ']));
        expect(fn () => $permissions->insert($permission))->toThrow(QueryException::class);
        expect(fn () => $permissions->insert(array_replace($permission, ['name' => 'bad', 'tenant_type' => 'org'])))->toThrow(QueryException::class);
        expect(fn () => (clone $permissions)->where('name', 'posts.view')->update(['tenant_id' => '1']))->toThrow(QueryException::class);
        expect(fn () => $storage->table('storage_state')->insert(['id' => 2, 'schema' => '{}']))->toThrow(QueryException::class);
    }
}
