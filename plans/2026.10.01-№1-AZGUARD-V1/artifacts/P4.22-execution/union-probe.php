<?php
require '/home/vostrikov/projects/packages/azguard/vendor/autoload.php';
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use AzGuard\Authorization\Query\PredicateCompiler;
use AzGuard\Kernel\Decision\AccessPredicate;
$manager = new Manager;
$manager->addConnection(['driver'=>'sqlite','database'=>':memory:']);
$manager->setAsGlobal(); $manager->bootEloquent();
$manager->schema()->create('probe_resources', function(Blueprint $table):void { $table->integer('id'); });
$manager->table('probe_resources')->insert([['id'=>1],['id'=>2]]);
class UnionProbeResource extends Model { protected $table='probe_resources'; }
$query=UnionProbeResource::query()->where('id',1)->union(UnionProbeResource::query()->where('id',2));
try {
 (new PredicateCompiler)->constrain($query,AccessPredicate::deny());
 print(json_encode(['sql'=>$query->toSql(),'ids'=>$query->pluck('id')->all()])."\n");
} catch(Throwable $error) { print(json_encode(['error'=>$error->getMessage()])."\n"); }
