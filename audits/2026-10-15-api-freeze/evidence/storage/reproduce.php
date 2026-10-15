<?php

declare(strict_types=1);

// Audit-only reproduction. Every database is explicitly forced to SQLite :memory: before any DDL/write.
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
putenv('AUTHORITY_REPLICA_TEST');
unset($_ENV['AUTHORITY_REPLICA_TEST'], $_SERVER['AUTHORITY_REPLICA_TEST']);

require dirname(__DIR__, 4).'/vendor/autoload.php';

use AzGuard\Contracts\Changes\ChangeParticipant;
use AzGuard\Contracts\Changes\OwnedRowsWriter;
use AzGuard\Changes\ChangeResult;
use AzGuard\Audit\AuditOptions;
use AzGuard\Audit\AuditPlugin;
use AzGuard\Audit\Internal\AuditSchema;
use AzGuard\AzGuard;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Schema\Field;
use AzGuard\Schema\FieldTarget;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Contracts\Console\Kernel;

$case = new class('audit') extends TestCase
{
    protected function databaseConnectionConfig(): array
    {
        return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('azguard.schedule.enabled', false);
    }
};
$app = $case->createApplication();
$connection = $app['db']->connection();
if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
    throw new RuntimeException('Audit reproduction requires SQLite :memory:.');
}
$evidence = ['isolation' => ['driver' => $connection->getDriverName(), 'database' => $connection->getDatabaseName()]];
CrmWorld::seed();

function auditGrant(int $subject, int $project, array $fields = []): ChangeResult
{
    return app(AzGuard::class)->panel('crm')->inTenant(W::tenant())->for(W::user($subject))
        ->grantRole('analyst', on: W::project($project), fields: $fields);
}

$injected = (array) $connection->table('azg_role_grants')->first();
unset($injected['id']);
$injected['role'] = 'analyst';
$injected['subject_id'] = '1';
$injected['context_key'] = W::project(5)->key();
$injected['context_id'] = '5';
$injected['origin'] = 'participant-case-bypass';
$participant = new class($injected) implements ChangeParticipant
{
    public function __construct(private array $injected) {}

    public function tables(): array
    {
        return ['ROLE_GRANTS'];
    }

    public function record(array $records, OwnedRowsWriter $rows): void
    {
        $rows->insert('ROLE_GRANTS', [$this->injected]);
    }
};
$panel = W::panel(configure: static fn (PanelBuilder $builder) => $builder->participants([$participant]));
$before = CrmWorld::decide($panel, client: 6, user: 1);
$result = auditGrant(2, 1);
// The participant changed subject 1, while the legitimate effect names subject 2.
$injectedRows = $connection->table('azg_role_grants')->where('origin', 'participant-case-bypass')->count();
$revision = $connection->table('azg_subject_revisions')->where('panel', 'crm')->where('subject_type', 'crm.user')->where('subject_id', '1')->value('revision');
$app->forgetScopedInstances();
$after = CrmWorld::decide($panel, client: 6, user: 1);
$evidence['participant_core_table_case_bypass'] = [
    'declared_table' => 'ROLE_GRANTS', 'compilation' => 'accepted',
    'injected_core_rows' => $injectedRows, 'reported_effects' => count($result->effects),
    'changed_subject_revision' => $revision, 'access_before' => $before->allowed(), 'access_after_new_read' => $after->allowed(),
];
if ($injectedRows !== 1 || count($result->effects) !== 1 || $revision !== null || $before->allowed() || ! $after->allowed()) {
    throw new RuntimeException('Unexpected participant reproduction outcome: '.json_encode($evidence));
}

$connection->getSchemaBuilder()->table('azg_role_grants', static fn (Blueprint $table) => $table->json('tags')->nullable());
$panel = W::panel(configure: static fn (PanelBuilder $builder) => $builder->fields(FieldTarget::RoleGrant, [Field::string('tags')->multiple()]));
try {
    $result = auditGrant(2, 5, fields: ['tags' => ['finance', 'sales']]);
    $evidence['multiple_json_column'] = ['compilation' => 'accepted', 'result' => 'written', 'fields' => $result->record?->fields];
} catch (Throwable $error) {
    $evidence['multiple_json_column'] = ['compilation' => 'accepted', 'exception' => $error::class, 'message' => $error->getMessage()];
}

$descriptor = app(AzGuard::class)->storage();
AuditSchema::create($descriptor);
$panel = W::panel(configure: static fn (PanelBuilder $builder) => $builder->plugins([AuditPlugin::make()])->options(new AuditOptions(-10)));
auditGrant(3, 2);
$beforeCount = $connection->table('azg_audit_log')->count();
$exit = $app->make(Kernel::class)->call('azguard:audit:prune', ['--panel' => 'crm', '--force' => true]);
$evidence['invalid_audit_options'] = [
    'retention_days' => $panel->options(AuditOptions::class)->retentionDays,
    'fresh_rows_before' => $beforeCount, 'fresh_rows_after' => $connection->table('azg_audit_log')->count(),
    'command_exit' => $exit, 'output' => trim($app->make(Kernel::class)->output()),
];

$panel = W::panel(configure: static fn (PanelBuilder $builder) => $builder->plugins([AuditPlugin::make()]));
auditGrant(3, 5);
$connection->table('azg_audit_log')->update(['occurred_at' => '2026-01-01 00:00:00']);
$secondary = $app['db']->connection('secondary');
if ($secondary->getDriverName() !== 'sqlite' || $secondary->getDatabaseName() !== ':memory:') {
    throw new RuntimeException('Second audit reproduction database is not isolated.');
}
$secondary->getSchemaBuilder()->create('azg_audit_log', static function (Blueprint $table): void {
    $table->id();
    $table->string('panel');
    $table->dateTime('occurred_at');
});
$secondary->table('azg_audit_log')->insert(['panel' => 'crm', 'occurred_at' => '2026-01-01 00:00:00']);
$app['db']->setDefaultConnection('secondary');
$exit = $app->make(Kernel::class)->call('azguard:audit:prune', ['--panel' => 'crm', '--force' => true]);
$evidence['default_connection_descriptor_drift'] = [
    'authority_storage_connection' => CrmWorld::storage()->connectionName(),
    'descriptor_connection' => $descriptor->connection,
    'current_default_connection' => $app['db']->getDefaultConnection(),
    'authority_journal_rows_after_prune' => $connection->table('azg_audit_log')->count(),
    'other_database_rows_after_prune' => $secondary->table('azg_audit_log')->count(),
    'command_exit' => $exit, 'output' => trim($app->make(Kernel::class)->output()),
];

file_put_contents(__DIR__.'/observed.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
foreach ($app['db']->getConnections() as $opened) {
    $opened->disconnect();
}
