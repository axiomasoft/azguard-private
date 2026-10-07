<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Changes;

use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use Closure;
use Illuminate\Database\Schema\Blueprint;

/**
 * A cooperating host fence (R29): under the panel lock that the mutation already holds, lock the host's active build
 * marker and the referenced project row on the SAME connection, then compare them with what the form or job prepared.
 * Deployments and owner transfers take the same panel-state → host-row order. Retry-safe: it reads again every attempt.
 */
final class HostFencePipe
{
    public static ?string $preparedBuild = null;

    /** @var array<string, int> project id => revision the preparation read */
    public static array $preparedRevisions = [];

    public static int $calls = 0;

    public function handle(Change $change, Closure $next): ChangeResult
    {
        self::$calls++;
        $context = $change->context();
        $connection = CrmWorld::storage()->connection();
        $marker = $connection->table('crm_active_build')->where('id', 1)->lockForUpdate()->first();

        if ($marker === null || $marker->fingerprint !== self::$preparedBuild || $marker->fingerprint !== $context->state->fingerprint) {
            $change->cancel('The active build changed after the change was prepared.');
        }

        if (! $change->scope->context->isGlobal()) {
            $project = (string) $change->scope->context->id();
            $row = $connection->table('crm_project_revisions')->where('project_id', $project)->lockForUpdate()->first();

            if ($row === null || (int) $row->revision !== (self::$preparedRevisions[$project] ?? -1)
                || (string) $row->organization_id !== $change->scope->tenant->id()) {
                $change->cancel('Project '.$project.' changed owner after the change was prepared.');
            }
        }

        return $next($change);
    }

    public static function install(string $fingerprint): void
    {
        $schema = CrmWorld::storage()->connection()->getSchemaBuilder();
        $schema->dropIfExists('crm_active_build');
        $schema->dropIfExists('crm_project_revisions');
        $schema->create('crm_active_build', static function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('fingerprint', 128);
        });
        $schema->create('crm_project_revisions', static function (Blueprint $table): void {
            $table->string('project_id', 32)->primary();
            $table->string('organization_id', 32);
            $table->integer('revision');
        });
        $connection = CrmWorld::storage()->connection();
        $connection->table('crm_active_build')->insert(['id' => 1, 'fingerprint' => $fingerprint]);
        foreach ([1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 1] as $project => $organization) {
            $connection->table('crm_project_revisions')->insert(['project_id' => (string) $project, 'organization_id' => (string) $organization, 'revision' => 1]);
        }
        self::$preparedBuild = $fingerprint;
        self::$preparedRevisions = array_fill_keys(['1', '2', '3', '4', '5'], 1);
        self::$calls = 0;
    }

    public static function uninstall(): void
    {
        $schema = CrmWorld::storage()->connection()->getSchemaBuilder();
        $schema->dropIfExists('crm_active_build');
        $schema->dropIfExists('crm_project_revisions');
        self::$preparedBuild = null;
        self::$preparedRevisions = [];
        self::$calls = 0;
    }
}
