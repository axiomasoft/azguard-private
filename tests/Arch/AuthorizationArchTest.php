<?php

declare(strict_types=1);

use AzGuard\Tests\Arch\SourceScan;

arch('authorization coordinator never reaches ambient auth or framework gate')
    ->expect('AzGuard\Authorization')->not->toUse(['Illuminate\Support\Facades\Auth', 'Illuminate\Support\Facades\Gate', 'AzGuard\Changes']);
arch('sources use contracts and values without importing authorization implementations')
    ->expect('AzGuard\Sources')->not->toUse(['AzGuard\Authorization']);
it('proves the authorization source boundary RED against a scratch copy', function (): void {
    $directory = sys_get_temp_dir().'/azguard-authority-arch-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $file = $directory.'/InjectedSource.php';
    file_put_contents($file, "<?php\nnamespace AzGuard\\Sources\\Probe;\nuse AzGuard\\Authorization\\Internal\\Authorizer;\nfinal class InjectedSource { public function __construct(Authorizer \$engine) {} }\n");
    $dependencies = ['AzGuard\\Authorization'];
    expect(SourceScan::restrictedReferencesIn([$file], $dependencies, []))->not->toBe([])
        ->and(SourceScan::restrictedReferencesIn(SourceScan::files('packages/core/src/Sources'), $dependencies, []))->toBe([]);
});

it('keeps the coordinator provenance exception read only', function (): void {
    expect(SourceScan::transactionCalls(dirname(__DIR__, 2).'/packages/core/src/Authorization/Internal/Pipeline/Stages/AuthorityStage.php'))->toBe([]);
});

/**
 * `instanceof` checks against a concrete source class in the given files, as "file:line Class".
 *
 * @param  list<string>  $files
 * @return list<string>
 */
function concreteSourceChecks(array $files): array
{
    $concrete = [
        'AzGuard\Sources\Database\DatabaseSource',
        'AzGuard\Sources\Folder\FolderSource',
        'AzGuard\Sources\Gate\GateSource',
        'AzGuard\Sources\Relation\RelationSource',
    ];
    $found = [];

    foreach ($files as $file) {
        $tokens = array_values(array_filter(PhpToken::tokenize((string) file_get_contents($file)), static fn (PhpToken $token): bool => ! $token->isIgnorable()));
        $namespace = '';
        $imports = [];

        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE) && isset($tokens[$i + 1])) {
                $namespace = $tokens[$i + 1]->text;
            }

            // A trait `use` adds an alias no instanceof names; a closure `use (` is not followed by a name.
            if ($token->is(T_USE) && isset($tokens[$i + 1]) && $tokens[$i + 1]->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING])) {
                $name = ltrim($tokens[$i + 1]->text, '\\');
                $alias = isset($tokens[$i + 2], $tokens[$i + 3]) && $tokens[$i + 2]->is(T_AS) ? $tokens[$i + 3]->text : substr((string) strrchr('\\'.$name, '\\'), 1);
                $imports[$alias] = $name;
            }

            if (! $token->is(T_INSTANCEOF) || ! isset($tokens[$i + 1]) || ! $tokens[$i + 1]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }
            $name = $tokens[$i + 1]->text;
            $first = explode('\\', $name)[0];
            $class = match (true) {
                str_starts_with($name, '\\') => ltrim($name, '\\'),
                isset($imports[$first]) => $imports[$first].substr($name, strlen($first)),
                default => $namespace.'\\'.$name,
            };

            if (in_array($class, $concrete, true)) {
                $found[] = basename($file).':'.$token->line.' '.$class;
            }
        }
    }

    return $found;
}

it('keeps concrete-source branches out of the decision stages, query composition and Visibility (D5)', function (): void {
    $root = dirname(__DIR__, 2).'/packages/core/src/Authorization';
    $files = [
        ...SourceScan::files('packages/core/src/Authorization/Internal/Pipeline/Stages'),
        ...SourceScan::files('packages/core/src/Authorization/Internal/Query'),
        $root.'/Visibility.php',
    ];

    expect(concreteSourceChecks($files))->toBe([])
        // The coordinator keeps its database mechanics, so the scan finds them there.
        ->and(concreteSourceChecks([$root.'/Internal/ReadAttempt.php']))->not->toBe([]);
});

it('proves the concrete-source branch rule RED against a scratch copy', function (): void {
    $directory = sys_get_temp_dir().'/azguard-source-branch-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $file = $directory.'/InjectedStage.php';
    file_put_contents($file, "<?php\nnamespace AzGuard\\Authorization\\Pipeline\\Stages;\nuse AzGuard\\Sources\\Folder\\FolderSource as Folder;\nfinal class InjectedStage { public function run(object \$source): bool { return \$source instanceof Folder || \$source instanceof \\AzGuard\\Sources\\Database\\DatabaseSource; } }\n");

    expect(concreteSourceChecks([$file]))->toBe(['InjectedStage.php:4 AzGuard\Sources\Folder\FolderSource', 'InjectedStage.php:4 AzGuard\Sources\Database\DatabaseSource']);
    unlink($file);
    rmdir($directory);
});
