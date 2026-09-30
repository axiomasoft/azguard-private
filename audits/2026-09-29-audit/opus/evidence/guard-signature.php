<?php
// Signature probe against installed Eloquent; does not execute the target AzGuard API.
require dirname(__DIR__, 4).'/vendor/autoload.php';
final readonly class AuditSubjectAccess { public function __construct(public string $panel) {} }
trait AuditGuardAdapter {
    public function guard(array|string $guarded): static|AuditSubjectAccess {
        if (is_array($guarded)) { return parent::guard($guarded); }
        return new AuditSubjectAccess($guarded);
    }
}
final class AuditGuardModel extends Illuminate\Database\Eloquent\Model { use AuditGuardAdapter; }
$model = new AuditGuardModel;
if ($model->guard(guarded: ['secret']) !== $model || $model->getGuarded() !== ['secret']) { throw new RuntimeException('Native guard failed'); }
$model->mergeGuarded(['token']);
$before = $model->getGuarded();
$crm = $model->guard('crm'); $admin = $model->guard('admin');
if ($crm->panel !== 'crm' || $admin->panel !== 'admin' || $before !== ['secret', 'token'] || $model->getGuarded() !== $before) { throw new RuntimeException('String adapter changed mass assignment'); }
if (Illuminate\Database\Eloquent\Model::isUnguarded()) { throw new RuntimeException('Global mass assignment changed'); }
$model->fill(['secret' => 'blocked']);
if (array_key_exists('secret', $model->getAttributes())) { throw new RuntimeException('Native fill guard stopped working'); }
echo 'PASS: class load, array/named argument, mergeGuarded, string wrappers, immutable guarded state, native guarded-field discard. Signature probe only; target package API is not implemented.'.PHP_EOL;
