<?php
// Bounded language-contract probe. Does not run AzGuard authorization, SQL, UI or package runtime.
require dirname(__DIR__, 4).'/vendor/autoload.php';

use Illuminate\Database\Eloquent\Model;
use Illuminate\Container\Container;

abstract class AuditOopPlugin
{
    private ?string $prefix = null;
    public function prefixed(string $prefix): static
    {
        $copy = clone $this;
        $copy->prefix = $prefix;
        return $copy;
    }
    public function prefix(): ?string { return $this->prefix; }
}
final class AuditProjectModel extends Model {}
final class AuditClientModel extends Model {}
final readonly class AuditCrmModels
{
    /** @param class-string<Model> $project @param class-string<Model> $client */
    public function __construct(public string $project, public string $client)
    {
        foreach ([$project, $client] as $class) {
            if (!is_a($class, Model::class, true) || !(new ReflectionClass($class))->isInstantiable()) {
                throw new InvalidArgumentException('Expected concrete Model class');
            }
        }
    }
}
abstract class AuditContextBase {} // No inherited generic make.
final class AuditProjectContext extends AuditContextBase
{
    private function __construct(public readonly string $model) {}
    public static function make(string $model): self { return new self($model); }
}
final class AuditCrmPlugin extends AuditOopPlugin
{
    private function __construct(public readonly AuditCrmModels $models, public readonly AuditProjectContext $projects) {}
    public static function make(AuditCrmModels $models, AuditProjectContext $projects): self
    {
        if ($models->project !== $projects->model) { throw new InvalidArgumentException('Project model mismatch'); }
        return new self($models, $projects);
    }
}
final class AuditTrailPluginProbe extends AuditOopPlugin
{
    private function __construct(public readonly int $retentionDays) {}
    public static function make(int $retentionDays): self
    {
        if ($retentionDays < 1) { throw new InvalidArgumentException('retentionDays'); }
        return new self($retentionDays);
    }
}
final class AuditDependencyProbe {}
final class AuditRoleClassProbe {}
function requireOopCondition(bool $condition, string $reason): void
{
    if (!$condition) { throw new RuntimeException($reason); }
}
$models = new AuditCrmModels(project: AuditProjectModel::class, client: AuditClientModel::class);
$context = AuditProjectContext::make(model: AuditProjectModel::class);
$crm = AuditCrmPlugin::make(models: $models, projects: $context);
$other = AuditCrmPlugin::make(models: $models, projects: $context);
$audit = AuditTrailPluginProbe::make(retentionDays: 90);
requireOopCondition($crm !== $other && $audit->retentionDays === 90, 'Own typed factories must produce fresh objects');
requireOopCondition(!method_exists(AuditOopPlugin::class, 'make') && !method_exists(AuditContextBase::class, 'make'), 'Base must not impose factory signature');
$prefixed = $crm->prefixed('sales');
requireOopCondition($prefixed !== $crm && $prefixed->prefix() === 'sales' && $crm->prefix() === null, 'Fluent helper must clone');
$rejections = 0;
try { new AuditCrmModels(project: stdClass::class, client: AuditClientModel::class); } catch (InvalidArgumentException) { $rejections++; }
try { new AuditCrmModels(project: Model::class, client: AuditClientModel::class); } catch (InvalidArgumentException) { $rejections++; }
try { AuditCrmPlugin::make(models: $models, projects: AuditProjectContext::make(model: AuditClientModel::class)); } catch (InvalidArgumentException) { $rejections++; }
try { AuditTrailPluginProbe::make(options: ['retentionDays' => 90]); } catch (Error) { $rejections++; }
try { AuditCrmPlugin::make(models: [], projects: $context); } catch (TypeError) { $rejections++; }
requireOopCondition($rejections === 5, 'Invalid named/type/model inputs must reject');
$container = new Container;
$user = new AuditProjectModel;
$role = new AuditRoleClassProbe;
$seen = $container->call(
    fn (Model $user, AuditRoleClassProbe $role, AuditDependencyProbe $service) => [$user, $role, $service],
    ['user' => $user, 'role' => $role],
);
requireOopCondition($seen[0] === $user && $seen[1] === $role && $seen[2] instanceof AuditDependencyProbe, 'Explicit models and native service DI');
echo 'PASS: concrete typed factories without inherited make; named params; fresh objects; cloning; model/type errors; explicit runtime inputs + native service DI. Bounded probe only.'.PHP_EOL;
