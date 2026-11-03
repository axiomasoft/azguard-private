<?php

declare(strict_types=1);

// Guard the temporary fail-closed state until the complete evidence path exists.
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/package-evidence.php';

use Symfony\Component\Yaml\Yaml;

function disabledProducer(array $workflow): bool
{
    // Exact shape prevents an added job, trigger, environment, expression or step
    // from reading a secret or doing work before the unconditional failure.
    $serialized = json_encode($workflow, JSON_THROW_ON_ERROR);
    if (str_contains($serialized, '${{') || str_contains(strtolower($serialized), 'secrets.')
        || array_diff(array_keys($workflow), ['name', 'on', 'permissions', 'defaults', 'jobs']) !== []
        || array_keys($workflow['on'] ?? []) !== ['workflow_dispatch']
        || $workflow['on']['workflow_dispatch'] !== null
        || ($workflow['permissions'] ?? null) !== ['contents' => 'read']
        || ($workflow['defaults'] ?? null) !== ['run' => ['shell' => 'bash']]
        || array_keys($workflow['jobs'] ?? []) !== ['blocked']) {
        return false;
    }
    $job = $workflow['jobs']['blocked'];
    if (! is_array($job) || array_diff(array_keys($job), ['name', 'runs-on', 'steps']) !== []
        || ($job['runs-on'] ?? null) !== 'ubuntu-latest' || ! is_array($job['steps'] ?? null)
        || count($job['steps']) !== 1 || ! is_array($job['steps'][0] ?? null)) {
        return false;
    }
    $step = $job['steps'][0];
    return array_diff(array_keys($step), ['name', 'run']) === []
        && is_string($step['name'] ?? null)
        && is_string($step['run'] ?? null)
        && preg_match("~\\Aset -Eeuo pipefail\\necho '[^'\\n]*'\\nexit 1\\n?\\z~", $step['run']) === 1;
}

function guardsHold(array $split, array $core, array $filament): bool
{
    $steps = $split['jobs']['split']['steps'] ?? [];
    $guard = $steps[3] ?? [];
    $script = $guard['run'] ?? '';

    return ($split['jobs']['split']['strategy']['matrix']['package'] ?? null) === [['dir' => 'core', 'name' => 'azguard']]
        && ($guard['name'] ?? null) === 'Require verified release setup'
        && ! isset($guard['if'])
        && is_string($script)
        && str_contains($script, 'php bin/prepare-release-setup.php')
        && str_contains($script, 'php bin/verify-release-evidence.php --setup build/release/setup-request.json')
        && disabledProducer($core)
        && disabledProducer($filament);
}

$root = dirname(__DIR__);
$split = Yaml::parseFile($root.'/.github/workflows/split.yml');
$core = Yaml::parseFile($root.'/packages/core/.github/workflows/provenance.yml');
$filament = Yaml::parseFile($root.'/packages/filament/.github/workflows/provenance.yml');
if (! guardsHold($split, $core, $filament)) {
    throw new RuntimeException('release evidence guard is missing');
}
foreach ([$core, $filament] as $workflow) {
    $unsafe = $workflow;
    $unsafe['on']['push'] = ['branches' => ['release-candidate/**']];
    if (disabledProducer($unsafe)) {
        throw new RuntimeException('producer enabled on push was accepted');
    }
    $unsafe = $workflow;
    $unsafe['jobs']['blocked']['steps'][0]['env']['TOKEN'] = '${{ secrets.PRIVATE_RELEASE_READ_TOKEN }}';
    if (disabledProducer($unsafe)) {
        throw new RuntimeException('producer with a secret was accepted');
    }
    $unsafe = $workflow;
    $unsafe['jobs']['blocked']['steps'][0]['run'] = "set -Eeuo pipefail\necho '\${{ secrets.PRIVATE_RELEASE_READ_TOKEN }}'\nexit 1\n";
    if (disabledProducer($unsafe)) {
        throw new RuntimeException('producer with a secret expression before failure was accepted');
    }
    $unsafe = $workflow;
    $unsafe['jobs']['blocked']['steps'][0]['run'] = "set -Eeuo pipefail\necho 'test'\nexit 0\n";
    if (disabledProducer($unsafe)) {
        throw new RuntimeException('producer that can succeed was accepted');
    }
    $unsafe = $workflow;
    $unsafe['jobs']['blocked']['steps'][0]['run'] = "set -Eeuo pipefail\ncurl https://example.invalid\nexit 1\n";
    if (disabledProducer($unsafe)) {
        throw new RuntimeException('producer with work before failure was accepted');
    }
    $unsafe = $workflow;
    $unsafe['permissions']['id-token'] = 'write';
    if (disabledProducer($unsafe)) {
        throw new RuntimeException('producer with signing permission was accepted');
    }
    $unsafe = $workflow;
    $unsafe['jobs']['blocked']['steps'][] = ['run' => 'true'];
    if (disabledProducer($unsafe)) {
        throw new RuntimeException('producer with an extra step was accepted');
    }
}
$unsafe = $split;
$unsafe['jobs']['split']['steps'][3]['run'] = 'true';
if (guardsHold($unsafe, $core, $filament)) {
    throw new RuntimeException('missing split guard was accepted');
}
$unsafe = $split;
$unsafe['jobs']['split']['strategy']['matrix']['package'][] = ['dir' => 'filament', 'name' => 'azguard-filament'];
if (guardsHold($unsafe, $core, $filament)) {
    throw new RuntimeException('unpublished Filament split was accepted');
}
echo "Unverified release setup and untrusted producers are blocked.\n";

$directory = sys_get_temp_dir().'/azguard-inventory-'.bin2hex(random_bytes(8));
mkdir($directory);
try {
    $zipPath = $directory.'/package.zip';
    $bomPath = $directory.'/source.cdx.json';
    $zip = new ZipArchive;
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true
        || ! $zip->addFromString('src/example.php', 'original')
        || ! $zip->addFromString('composer.json', '{"name":"axiomasoft/azguard","version":"2.0.0-rc.1"}')
        || ! $zip->close()) {
        throw new RuntimeException('cannot create fixture');
    }
    $inventory = packageSourceInventory($zipPath, 'axiomasoft/azguard', '2.0.0-rc.1');
    $format = json_decode((string) file_get_contents(__DIR__.'/package-evidence-format.json'), true, 512, JSON_THROW_ON_ERROR);
    $fixture = json_decode((string) file_get_contents(__DIR__.'/package-evidence-compat.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($format['compatibility_fixture'] ?? null) !== 'bin/package-evidence-compat.json' || $inventory !== $fixture) {
        throw new RuntimeException('source inventory compatibility fixture changed');
    }
    file_put_contents($bomPath, json_encode($inventory, JSON_THROW_ON_ERROR));
    $command = [PHP_BINARY, __DIR__.'/package-evidence.php', '--check-source-sbom', $zipPath, 'axiomasoft/azguard', '2.0.0-rc.1', $bomPath];
    $run = static function (array $command): int {
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('cannot start verifier');
        }
        return proc_close($process);
    };
    if ($run($command) !== 0) {
        throw new RuntimeException('valid ZIP inventory rejected');
    }
    $validate = ['bash', __DIR__.'/security-tools.sh', 'cyclonedx-cli', '--', 'validate', '--input-file', $bomPath, '--input-format', 'json', '--input-version', 'v1_5', '--fail-on-errors'];
    if ($run($validate) !== 0) {
        throw new RuntimeException('generated source SBOM failed CycloneDX schema validation');
    }
    $invalidSchema = $inventory;
    $invalidSchema['components'][0]['type'] = 'not-a-component-type';
    file_put_contents($bomPath, json_encode($invalidSchema, JSON_THROW_ON_ERROR));
    if ($run($validate) === 0) {
        throw new RuntimeException('CycloneDX schema validator accepted invalid component');
    }
    $inventory['components'][0]['hashes'][0]['content'] = str_repeat('0', 64);
    file_put_contents($bomPath, json_encode($inventory, JSON_THROW_ON_ERROR));
    if ($run($command) === 0) {
        throw new RuntimeException('modified SBOM accepted');
    }
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $zip->addFromString('../escape', 'unsafe');
    $zip->close();
    if ($run($command) === 0) {
        throw new RuntimeException('unsafe ZIP accepted');
    }
} finally {
    @unlink($zipPath);
    @unlink($bomPath);
    @rmdir($directory);
}
echo "ZIP inventory and CycloneDX schema fixtures passed.\n";

$lock = ['packages' => [
    ['name' => 'axiomasoft/azguard', 'version' => '2.0.0'],
    ['name' => 'example/dependency', 'version' => 'v1.2.3'],
], 'packages-dev' => []];
$resolved = ['bomFormat' => 'CycloneDX', 'specVersion' => '1.5', 'version' => 1,
    'metadata' => ['component' => ['type' => 'library', 'name' => 'axiomasoft/azguard', 'version' => '2.0.0']],
    'components' => [['type' => 'library', 'name' => 'dependency', 'group' => 'example', 'version' => 'v1.2.3', 'purl' => 'pkg:composer/example/dependency@v1.2.3']]];
if (packageResolvedGraphErrors($lock, $resolved, 'axiomasoft/azguard', '2.0.0') !== []) {
    throw new RuntimeException('valid resolved graph rejected');
}
$badResolved = $resolved;
$badResolved['components'][0]['version'] = 'v9.0.0';
if (packageResolvedGraphErrors($lock, $badResolved, 'axiomasoft/azguard', '2.0.0') === []) {
    throw new RuntimeException('mismatched resolved dependency accepted');
}
$badResolved = $resolved;
$badResolved['components'] = [];
if (packageResolvedGraphErrors($lock, $badResolved, 'axiomasoft/azguard', '2.0.0') === []) {
    throw new RuntimeException('partial resolved graph accepted');
}
echo "Resolved graph fixtures passed.\n";

$directory = sys_get_temp_dir().'/azguard-syft-'.bin2hex(random_bytes(8));
mkdir($directory);
try {
    file_put_contents($directory.'/composer.json', '{"name":"fixture/consumer","require":{"axiomasoft/azguard":"2.0.0"}}');
    file_put_contents($directory.'/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
    $rawBom = packageEvidenceCommand(['bash', __DIR__.'/security-tools.sh', 'syft', '--', 'dir:'.$directory, '-o', 'cyclonedx-json@1.6', '--quiet']);
    $generated = json_decode($rawBom, true, 512, JSON_THROW_ON_ERROR);
    $generated['components'] = array_values(array_filter($generated['components'] ?? [], static fn (array $component): bool => str_starts_with((string) ($component['purl'] ?? ''), 'pkg:composer/') && ($component['name'] ?? null) !== 'azguard' && trim(($component['group'] ?? '').'/'.($component['name'] ?? ''), '/') !== 'axiomasoft/azguard'));
    $generated['metadata']['component'] = ['type' => 'library', 'name' => 'axiomasoft/azguard', 'version' => '2.0.0', 'purl' => 'pkg:composer/axiomasoft/azguard@2.0.0'];
    if (($errors = packageResolvedGraphErrors($lock, $generated, 'axiomasoft/azguard', '2.0.0')) !== []) {
        throw new RuntimeException('Syft lock graph mismatch: '.implode('; ', $errors));
    }
    file_put_contents($directory.'/generated.cdx.json', json_encode($generated, JSON_THROW_ON_ERROR));
    $spec = $generated['specVersion'] ?? '';
    if (! in_array($spec, ['1.4', '1.5', '1.6'], true)) {
        throw new RuntimeException('unsupported Syft CycloneDX version');
    }
    packageEvidenceCommand(['bash', __DIR__.'/security-tools.sh', 'cyclonedx-cli', '--', 'validate', '--input-file', $directory.'/generated.cdx.json', '--input-format', 'json', '--input-version', 'v'.str_replace('.', '_', $spec), '--fail-on-errors']);
} finally {
    @unlink($directory.'/composer.json');
    @unlink($directory.'/composer.lock');
    @unlink($directory.'/generated.cdx.json');
    @rmdir($directory);
}
echo "Syft lock graph fixture passed.\n";

$directory = sys_get_temp_dir().'/azguard-mapping-'.bin2hex(random_bytes(8));
mkdir($directory);
try {
    mkdir($directory.'/mono/packages/core/src', 0777, true);
    file_put_contents($directory.'/mono/packages/core/composer.json', '{"name":"axiomasoft/azguard","description":"fixture","type":"library","license":"MIT"}');
    file_put_contents($directory.'/mono/packages/core/src/example.php', 'expected');
    $git = static fn (string ...$args): string => trim(packageEvidenceCommand(['git', '-C', $directory.'/mono', ...$args]));
    $git('init', '-q');
    $git('config', 'user.name', 'Fixture');
    $git('config', 'user.email', 'fixture@example.invalid');
    $git('add', 'packages/core');
    $git('commit', '-qm', 'fixture');
    $git('tag', 'v2.0.0');
    $splitSha = $git('subtree', 'split', '--prefix=packages/core', 'HEAD');
    packageEvidenceCommand(['git', 'clone', '-q', '--no-checkout', $directory.'/mono', $directory.'/split']);
    packageEvidenceCommand(['git', '-C', $directory.'/split', 'tag', '-f', 'v2.0.0', $splitSha]);
    packageEvidenceCommand(['git', '-C', $directory.'/split', 'checkout', '-q', '--detach', $splitSha]);
    mkdir($directory.'/package/src', 0777, true);
    mkdir($directory.'/built');
    $manifest = json_decode((string) file_get_contents($directory.'/mono/packages/core/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['version'] = '2.0.0';
    file_put_contents($directory.'/package/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    file_put_contents($directory.'/package/src/example.php', 'expected');
    packageEvidenceCommand(['composer', 'archive', '--format=zip', '--dir='.$directory.'/built', '--working-dir='.$directory.'/package', '--no-interaction']);
    $archives = glob($directory.'/built/*.zip') ?: [];
    if (count($archives) !== 1) {
        throw new RuntimeException('missing fixture ZIP');
    }
    $zipPath = $directory.'/built/azguard-core-2.0.0.zip';
    rename($archives[0], $zipPath);
    $sbom = $directory.'/built/source-core.cdx.json';
    file_put_contents($directory.'/built/resolution-core.lock', json_encode($lock, JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/built/sbom-core.cdx.json', json_encode($resolved, JSON_THROW_ON_ERROR));
    $writeSbom = static function () use ($zipPath, $sbom): void {
        file_put_contents($sbom, json_encode(packageSourceInventory($zipPath, 'axiomasoft/azguard', '2.0.0'), JSON_THROW_ON_ERROR));
    };
    $writeSbom();
    $builtManifest = packageBuildManifest($directory.'/mono', 'core', '2.0.0', $directory.'/built', null);
    $buildFormat = json_decode((string) file_get_contents(__DIR__.'/package-build-manifest-format.json'), true, 512, JSON_THROW_ON_ERROR);
    $buildFixture = json_decode((string) file_get_contents(__DIR__.'/package-build-manifest-compat.json'), true, 512, JSON_THROW_ON_ERROR);
    if (array_keys($builtManifest) !== $buildFormat['required'] || array_keys($buildFixture) !== $buildFormat['required']
        || array_keys($builtManifest['artifacts']) !== $buildFormat['artifact_kinds']
        || $builtManifest['schema'] !== $buildFixture['schema']) {
        throw new RuntimeException('package build manifest format changed');
    }
    if ($builtManifest['qualification'] !== 'nonrelease' || $builtManifest['split_tag_state'] !== 'expected'
        || $builtManifest['split_commit'] !== $splitSha || $builtManifest['workflow'] !== null
        || $builtManifest['artifacts']['zip']['sha256'] !== hash_file('sha256', $zipPath)) {
        throw new RuntimeException('incorrect nonrelease build manifest');
    }
    $identity = ['repository' => 'axiomasoft/azguard-private', 'source_sha' => $builtManifest['monorepo_sha'], 'tag' => 'v2.0.0', 'workflow_sha' => $builtManifest['monorepo_sha'], 'run_id' => '123', 'run_attempt' => '1'];
    $pending = packageBuildManifest($directory.'/mono', 'core', '2.0.0', $directory.'/built', $identity);
    if ($pending['qualification'] !== 'pending' || count($pending['qualification_references']) !== 1) {
        throw new RuntimeException('workflow manifest falsely qualified');
    }
    file_put_contents($directory.'/mono/packages/core/src/example.php', 'uncommitted change');
    if (packageBuildManifest($directory.'/mono', 'core', '2.0.0', $directory.'/built', null)['qualification'] !== 'nonrelease') {
        throw new RuntimeException('local committed-HEAD rehearsal not marked nonrelease');
    }
    try {
        packageBuildManifest($directory.'/mono', 'core', '2.0.0', $directory.'/built', $identity);
        throw new RuntimeException('workflow accepted dirty package input');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'workflow accepted dirty package input') {
            throw $error;
        }
    }
    file_put_contents($directory.'/mono/packages/core/src/example.php', 'expected');
    $releaseManifestPath = $directory.'/built/mapping-core.json';
    file_put_contents($releaseManifestPath, json_encode($pending, JSON_THROW_ON_ERROR));
    packageVerifyProducerManifest($directory.'/mono', $directory.'/split', $directory.'/built', 'core', 'v2.0.0', '123', '1');
    $runner = $directory.'/runner';
    $bundle = $directory.'/bundle';
    mkdir($runner);
    mkdir($bundle);
    mkdir($directory.'/fake-bin');
    mkdir($directory.'/split/dist');
    copy($zipPath, $directory.'/split/dist/package.zip');
    packageEvidenceCommand(['git', 'clone', '-q', '--no-checkout', $directory.'/mono', $runner.'/verified-release-source']);
    packageEvidenceCommand(['git', '-C', $runner.'/verified-release-source', 'checkout', '-q', '--detach', $builtManifest['monorepo_sha']]);
    mkdir($runner.'/verified-release-source/bin');
    mkdir($runner.'/verified-release-source/.github');
    foreach (['package-evidence.php', 'security-tools.sh'] as $file) {
        copy(__DIR__.'/'.$file, $runner.'/verified-release-source/bin/'.$file);
    }
    copy(dirname(__DIR__).'/.github/security-tools.lock', $runner.'/verified-release-source/.github/security-tools.lock');
    $subjects = ['azguard-core-2.0.0.zip', 'source-core.cdx.json', 'sbom-core.cdx.json', 'resolution-core.lock', 'mapping-core.json'];
    foreach ($subjects as $file) {
        $input = $directory.'/built/'.$file;
        if (is_file($input)) {
            copy($input, $bundle.'/'.$file);
        } else {
            file_put_contents($bundle.'/'.$file, 'other package fixture');
        }
    }
    $checksums = static function () use ($subjects, $bundle): void {
        $contents = '';
        foreach ($subjects as $file) {
            $contents .= hash_file('sha256', $bundle.'/'.$file).'  '.$file."\n";
        }
        file_put_contents($bundle.'/SHA256SUMS', $contents);
    };
    $checksums();
    file_put_contents($directory.'/fake-bin/gh', "#!/usr/bin/env bash\nset -eu\nwhile [[ \$# -gt 0 ]]; do if [[ \$1 == --dir ]]; then mkdir -p \"\$2\"; cp -a \"\$FIXTURE_BUNDLE/.\" \"\$2/\"; exit 0; fi; shift; done\nexit 1\n");
    chmod($directory.'/fake-bin/gh', 0700);
    $environment = getenv();
    $environment['PATH'] = $directory.'/fake-bin:'.$environment['PATH'];
    $environment['RUNNER_TEMP'] = $runner;
    $environment['FIXTURE_BUNDLE'] = $bundle;
    $environment['GH_TOKEN'] = 'fixture';
    $environment['GITHUB_REF'] = 'refs/heads/release-candidate/v2.0.0/123/1';
    $environment['GITHUB_REPOSITORY'] = 'axiomasoft/azguard';
    $environment['AZGUARD_TOOLS_DIR'] = dirname(__DIR__).'/var/tools';
    $producer = dirname(__DIR__).'/packages/core/.github/verify-release-artifacts.sh';
    $runProducer = static function () use ($producer, $directory, $environment, $runner): int {
        if (is_dir($runner.'/release-bundle')) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runner.'/release-bundle', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($runner.'/release-bundle');
        }
        $process = proc_open(['bash', $producer], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $directory.'/split', $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('cannot start producer fixture');
        }
        return proc_close($process);
    };
    if ($runProducer() !== 0) {
        throw new RuntimeException('valid final artifact producer fixture rejected');
    }
    $altered = $resolved;
    $altered['components'][0]['version'] = 'v9.0.0';
    file_put_contents($bundle.'/sbom-core.cdx.json', json_encode($altered, JSON_THROW_ON_ERROR));
    $forged = $pending;
    $forged['artifacts']['resolved_sbom']['sha256'] = hash_file('sha256', $bundle.'/sbom-core.cdx.json');
    file_put_contents($bundle.'/mapping-core.json', json_encode($forged, JSON_THROW_ON_ERROR));
    $checksums();
    if ($runProducer() === 0) {
        throw new RuntimeException('producer accepted mismatched SBOM after checksum and manifest update');
    }
    $wrong = $pending;
    $wrong['transform']['after_sha256'] = str_repeat('0', 64);
    file_put_contents($releaseManifestPath, json_encode($wrong, JSON_THROW_ON_ERROR));
    try {
        packageVerifyProducerManifest($directory.'/mono', $directory.'/split', $directory.'/built', 'core', 'v2.0.0', '123', '1');
        throw new RuntimeException('producer accepted altered manifest transform');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'producer accepted altered manifest transform') {
            throw $error;
        }
    }
    file_put_contents($releaseManifestPath, json_encode($pending, JSON_THROW_ON_ERROR));
    $mapping = packageLocalMapping($directory.'/mono', $directory.'/split', 'core', 'v2.0.0', '2.0.0', $zipPath, $sbom);
    $format = json_decode((string) file_get_contents(__DIR__.'/package-mapping-format.json'), true, 512, JSON_THROW_ON_ERROR);
    $fixture = json_decode((string) file_get_contents(__DIR__.'/package-mapping-compat.json'), true, 512, JSON_THROW_ON_ERROR);
    if (array_keys($mapping) !== $format['required'] || array_keys($fixture) !== $format['required']
        || $mapping['schema'] !== $fixture['schema'] || $mapping['qualification'] !== $fixture['qualification']
        || array_keys($mapping['transform']) !== array_keys($fixture['transform'])) {
        throw new RuntimeException('local mapping format changed');
    }
    if (($mapping['qualification'] ?? null) !== 'nonrelease' || ($mapping['split_commit'] ?? null) !== $splitSha
        || ($mapping['zip_sha256'] ?? null) !== hash_file('sha256', $zipPath)) {
        throw new RuntimeException('incorrect local mapping');
    }
    $mappingPath = $directory.'/mapping.json';
    $message = packageEvidenceCommand([PHP_BINARY, __DIR__.'/package-evidence.php', '--local-mapping', $directory.'/mono', $directory.'/split', 'core', 'v2.0.0', '2.0.0', $zipPath, $sbom, $mappingPath]);
    $record = json_decode((string) file_get_contents($mappingPath), true, 512, JSON_THROW_ON_ERROR);
    if (! str_contains($message, 'nonrelease') || $record !== $mapping || $record['workflow_sha'] !== null) {
        throw new RuntimeException('local CLI presented release qualification or changed the mapping');
    }
    $reject = static function (callable $call): void {
        try {
            $call();
        } catch (Throwable) {
            return;
        }
        throw new RuntimeException('invalid local mapping accepted');
    };
    $zip = new ZipArchive;
    $zip->open($zipPath);
    $zip->addFromString('src/example.php', 'changed');
    $zip->close();
    $writeSbom();
    $reject(static fn () => packageLocalMapping($directory.'/mono', $directory.'/split', 'core', 'v2.0.0', '2.0.0', $zipPath, $sbom));
    $reject(static fn () => packageBuildManifest($directory.'/mono', 'core', '2.0.0', $directory.'/built', null));
    $zip->open($zipPath);
    $zip->addFromString('src/example.php', 'expected');
    $manifest['description'] = 'altered';
    $zip->addFromString('composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    $zip->close();
    $writeSbom();
    $reject(static fn () => packageLocalMapping($directory.'/mono', $directory.'/split', 'core', 'v2.0.0', '2.0.0', $zipPath, $sbom));
    $manifest['description'] = 'fixture';
    $zip->open($zipPath);
    $zip->addFromString('composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    $zip->close();
    $writeSbom();
    packageLocalMapping($directory.'/mono', $directory.'/split', 'core', 'v2.0.0', '2.0.0', $zipPath, $sbom);
    packageEvidenceCommand(['git', '-C', $directory.'/split', 'tag', '-f', 'v2.0.0', $git('rev-parse', 'HEAD')]);
    $reject(static fn () => packageLocalMapping($directory.'/mono', $directory.'/split', 'core', 'v2.0.0', '2.0.0', $zipPath, $sbom));
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}
echo "Local nonrelease mapping fixtures passed.\n";

$fixtureRoot = sys_get_temp_dir().'/azguard-offline-cli-'.bin2hex(random_bytes(8));
mkdir($fixtureRoot);
try {
    $invoke = static function (array $arguments, ?array $env = null, ?string $cwd = null): array {
        $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        if (! is_resource($process)) {
            throw new RuntimeException('cannot start offline fixture test');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $output, (string) $error];
    };
    $cli = [PHP_BINARY, __DIR__.'/package-evidence.php'];
    $bad = [...$cli, '--source=worktree', '--version=invalid', '--out='.$fixtureRoot.'/bad', '--offline-fixture'];
    if ($invoke($bad)[0] === 0 || file_exists($fixtureRoot.'/bad')) {
        throw new RuntimeException('invalid offline fixture version accepted');
    }
    $bad = [...$cli, '--source=worktree', '--version=2.0.0-rc.1', '--out='.$fixtureRoot.'/missing-mode'];
    if ($invoke($bad)[0] === 0 || file_exists($fixtureRoot.'/missing-mode')) {
        throw new RuntimeException('worktree release without fixture mode accepted');
    }
    $env = getenv();
    mkdir($fixtureRoot.'/empty-tools');
    $env['AZGUARD_TOOLS_DIR'] = $fixtureRoot.'/empty-tools';
    $missingValidator = [...$cli, '--source=worktree', '--version=2.0.0-rc.1', '--out='.$fixtureRoot.'/missing-validator', '--offline-fixture'];
    if ($invoke($missingValidator, $env)[0] === 0 || file_exists($fixtureRoot.'/missing-validator')) {
        throw new RuntimeException('offline fixture accepted missing validator');
    }
    $root = dirname(__DIR__);
    $before = [];
    foreach (['core'] as $package) {
        $before[$package] = packageWorktreeDigest($root.'/packages/'.$package);
    }
    $out = $fixtureRoot.'/out';
    $command = [...$cli, '--source=worktree', '--version=2.0.0-rc.1', '--out='.$out, '--offline-fixture'];
    [$exit, $output, $error] = $invoke($command);
    if ($exit !== 0 || ! str_contains(strtolower($output), 'nonrelease') || ! str_contains($output, 'synthetic')) {
        throw new RuntimeException('offline worktree fixture did not complete as nonrelease: '.$error);
    }
    $data = json_decode((string) file_get_contents($out.'/evidence.json'), true, 512, JSON_THROW_ON_ERROR);
    $format = json_decode((string) file_get_contents(__DIR__.'/package-offline-fixture-v2-format.json'), true, 512, JSON_THROW_ON_ERROR);
    $compatible = json_decode((string) file_get_contents(__DIR__.'/package-offline-fixture-v2-compat.json'), true, 512, JSON_THROW_ON_ERROR);
    if (array_keys($data) !== $format['required'] || array_keys($compatible) !== $format['required']
        || ($data['schema'] ?? null) !== $format['schema'] || ($compatible['schema'] ?? null) !== $format['schema']
        || ($data['qualification'] ?? null) !== 'nonrelease' || ($data['source'] ?? null) !== 'worktree'
        || ($data['attestations'] ?? null) !== [] || ($data['split_mapping'] ?? null) !== 'not performed'
        || ! str_contains($data['consumer_graph'] ?? '', 'synthetic') || array_keys($data['packages']) !== $format['packages']) {
        throw new RuntimeException('offline fixture format or qualification mismatch');
    }
    if (glob($out.'/*filament*') !== []) {
        throw new RuntimeException('offline core-only fixture produced a Filament artifact');
    }
    foreach (['core'] as $package) {
        $record = $data['packages'][$package];
        $zip = $out.'/'.$record['zip'];
        $bom = json_decode((string) file_get_contents($out.'/'.$record['source_sbom']), true, 512, JSON_THROW_ON_ERROR);
        $resolved = json_decode((string) file_get_contents($out.'/'.$record['resolved_sbom']), true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode((string) file_get_contents($out.'/'.$record['resolution_lock']), true, 512, JSON_THROW_ON_ERROR);
        if (array_keys($record) !== array_keys($compatible['packages'][$package])
            || $record['source_worktree_sha256'] !== $before[$package] || packageWorktreeDigest($root.'/packages/'.$package) !== $before[$package]
            || $record['export_exclusions'] !== ['.github']
            || $record['split_commit'] !== null || $record['rebuild_matches'] !== true
            || $record['zip_sha256'] !== hash_file('sha256', $zip)
            || $bom !== packageSourceInventory($zip, $record['package_name'], '2.0.0-rc.1')
            || packageResolvedGraphErrors($lock, $resolved, $record['package_name'], '2.0.0-rc.1') !== []) {
            throw new RuntimeException('offline fixture package evidence mismatch');
        }
    }
    if ($invoke(['sha256sum', '--strict', '--check', 'SHA256SUMS'], null, $out)[0] !== 0) {
        throw new RuntimeException('offline fixture checksums failed');
    }
    $original = hash_file('sha256', $out.'/evidence.json');
    if ($invoke($command)[0] === 0 || $original !== hash_file('sha256', $out.'/evidence.json')) {
        throw new RuntimeException('offline fixture overwrote existing output');
    }
} finally {
    packageRemoveFixtureDirectory($fixtureRoot);
}
echo "Offline worktree fixture CLI passed without release qualification.\n";
