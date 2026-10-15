<?php

declare(strict_types=1);

// Read-only audit probe: load the repository's generator functions, not its CLI.
$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
require $root.'/bin/api-compat.php';
$source = file_get_contents($root.'/bin/api-manifest.php');
$start = strpos($source, 'function apiManifest(');
$end = strpos($source, "\n\$mode = ");
eval('use AzGuard\\Attributes\\Api; use AzGuard\\Attributes\\Spi; '.substr($source, $start, $end - $start));

eval(<<<'PHP'
namespace AuditBefore {
    #[\AzGuard\Attributes\Spi(kind: \AzGuard\Attributes\SpiKind::Implement, since: '1.0')]
    interface Provider { public function roles(): iterable; }
    /** @phpstan-type Entry array{id: string} */
    #[\AzGuard\Attributes\Api(since: '1.0')]
    final class Overview {
        /** @return list<Entry> */
        public function all(): array { return [['id' => 'admin']]; }
    }
}
namespace AuditAfter {
    #[\AzGuard\Attributes\Spi(kind: \AzGuard\Attributes\SpiKind::Implement, since: '1.0')]
    interface Provider { public function &roles(): iterable; }
    /** @phpstan-type Entry array{identifier: string} */
    #[\AzGuard\Attributes\Api(since: '1.0')]
    final class Overview {
        /** @return list<Entry> */
        public function all(): array { return [['identifier' => 'admin']]; }
    }
}
PHP);

$before = apiDescribe(new ReflectionClass(AuditBefore\Provider::class));
$after = apiDescribe(new ReflectionClass(AuditAfter\Provider::class));
$after['name'] = $before['name'];
$surface = static fn (array $type): array => ['api.core' => ['roots' => [], 'classes' => [$type]]];
$output = [
    'return_by_reference' => [
        'reflection_before' => (new ReflectionMethod(AuditBefore\Provider::class, 'roles'))->returnsReference(),
        'reflection_after' => (new ReflectionMethod(AuditAfter\Provider::class, 'roles'))->returnsReference(),
        'manifest_equal' => $before === $after,
        'comparison' => compatApi($surface($before), $surface($after)),
    ],
];

$runPhp = static function (string $code): array {
    $process = proc_open([PHP_BINARY], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], '<?php '.$code);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};

$output['return_by_reference']['consumer_before'] = $runPhp('interface Provider { public function roles(): iterable; } class Consumer implements Provider { public function roles(): iterable { return []; } } echo "loaded";');
$output['return_by_reference']['consumer_after'] = $runPhp('interface Provider { public function &roles(): iterable; } class Consumer implements Provider { public function roles(): iterable { return []; } } echo "loaded";');

$manifest = json_decode(file_get_contents($root.'/packages/core/api-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$event = array_values(array_filter($manifest['classes'], static fn (array $type): bool => $type['name'] === 'AzGuard\\Events\\AccessEvent'))[0];
$changed = $event;
$changed['readonly'] = false;
foreach ($changed['properties'] as &$property) {
    $property['readonly'] = false;
}
unset($property);
$output['readonly_base_removed'] = [
    'type' => $event['name'],
    'comparison' => compatApi($surface($event), $surface($changed)),
    'consumer_before' => $runPhp('abstract readonly class BaseEvent { public function __construct(public string $id) {} } readonly class ConsumerEvent extends BaseEvent {} echo "loaded";'),
    'consumer_after' => $runPhp('abstract class BaseEvent { public function __construct(public string $id) {} } readonly class ConsumerEvent extends BaseEvent {} echo "loaded";'),
];

$repeated = new Symfony\Component\Console\Input\InputDefinition([
    new Symfony\Component\Console\Input\InputOption('case', null, Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL | Symfony\Component\Console\Input\InputOption::VALUE_IS_ARRAY),
]);
$single = new Symfony\Component\Console\Input\InputDefinition([
    new Symfony\Component\Console\Input\InputOption('case', null, Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL),
]);
$command = static fn (Symfony\Component\Console\Input\InputDefinition $definition): array => [
    'azguard:make:permission' => ['synopsis' => $definition->getSynopsis(), 'exit_codes' => ['success' => 0]],
];
$arguments = ['azguard:make:permission', '--case=View=posts.view', '--case=Edit=posts.edit'];
$output['repeated_option_removed'] = [
    'production_signature' => 'packages/core/modules/devtools/src/Internal/Console/Make/MakePermissionCommand.php:35 {--case=*}',
    'synopsis_before' => $repeated->getSynopsis(),
    'synopsis_after' => $single->getSynopsis(),
    'comparison' => compatFormat('commands', $command($repeated), $command($single)),
    'consumer_before' => (new Symfony\Component\Console\Input\ArgvInput($arguments, $repeated))->getOption('case'),
    'consumer_after' => (new Symfony\Component\Console\Input\ArgvInput($arguments, $single))->getOption('case'),
];

$aliasBefore = apiDescribe(new ReflectionClass(AuditBefore\Overview::class));
$aliasAfter = apiDescribe(new ReflectionClass(AuditAfter\Overview::class));
$aliasAfter['name'] = $aliasBefore['name'];
$output['phpdoc_type_alias_changed'] = [
    'production_example' => 'packages/core/src/Diagnostics/PanelOverview.php:23-24,37 @phpstan-type Entry; @return list<Entry>',
    'manifest_equal' => $aliasBefore === $aliasAfter,
    'comparison' => compatApi($surface($aliasBefore), $surface($aliasAfter)),
    'consumer_before_has_id' => array_key_exists('id', (new AuditBefore\Overview)->all()[0]),
    'consumer_after_has_id' => array_key_exists('id', (new AuditAfter\Overview)->all()[0]),
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
