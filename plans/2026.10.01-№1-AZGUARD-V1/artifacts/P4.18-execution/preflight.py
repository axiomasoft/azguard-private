"""Read-only proof of the actual isolated host endpoints before engine acceptance."""
import json
import os
import subprocess

php = r'''
require 'vendor/autoload.php';
$case = new AzGuard\Tests\TestCase('preflight');
$method = new ReflectionMethod($case, 'databaseConnectionConfig');
$config = $method->invoke($case);
if ($config['database'] !== 'azguard_test') { throw new RuntimeException('Unexpected test database'); }
$driver = $config['driver'] === 'mariadb' ? 'mysql' : $config['driver'];
$pdo = new PDO($driver.':host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo json_encode(['driver'=>$config['driver'], 'host'=>$config['host'], 'port'=>$config['port'], 'database'=>$config['database'], 'version'=>$pdo->query('select version()')->fetchColumn()], JSON_THROW_ON_ERROR).PHP_EOL;
'''

for driver, service, port, variable in [
    ('pgsql', 'postgres', '5432', 'PGSQL_PORT'),
    ('mysql', 'mysql', '3306', 'MYSQL_PORT'),
    ('mariadb', 'mariadb', '3306', 'MARIADB_PORT'),
]:
    status = subprocess.check_output(['docker', 'compose', 'ps', '--format', 'json', service], text=True)
    state = json.loads(status.strip())
    if state['State'] != 'running' or state['Health'] != 'healthy':
        raise SystemExit(service + ' is not healthy')
    endpoint = subprocess.check_output(['docker', 'compose', 'port', service, port], text=True).strip()
    host, actual_port = endpoint.rsplit(':', 1)
    if host != '127.0.0.1':
        raise SystemExit('Unexpected host endpoint: ' + endpoint)
    environment = dict(os.environ, APP_ENV='testing', DB_CONNECTION=driver)
    environment[variable] = actual_port
    subprocess.run(['php', '-r', php], env=environment, check=True)
