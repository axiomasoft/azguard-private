<?php
$out=[];
foreach (['pgsql'=>25432,'mysql'=>23306,'mariadb'=>23307] as $d=>$p) {
 $pdo=new PDO(($d==='pgsql'?'pgsql':'mysql').':host=127.0.0.1;port='.$p.';dbname=azguard_test','azguard','azguard',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db=$pdo->query($d==='pgsql'?'select current_database()':'select database()')->fetchColumn();
 if($db!=='azguard_test')throw new RuntimeException('test target mismatch');
 $out[$d]=['host'=>'127.0.0.1','port'=>$p,'database'=>$db,'version'=>$pdo->query('select version()')->fetchColumn()];
}
$r=new Redis();$r->connect('127.0.0.1',26379);$out['redis']=['port'=>26379,'version'=>$r->info('server')['redis_version'],'test_database'=>15,'cleanup'=>'unique qualification prefix only'];
echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
