<?php

declare(strict_types=1);

// Internal harness. The registry is reviewed evidence, not discovery of published releases.
if ($argc !== 5) {
    fwrite(STDERR, "usage: php run-baselines.php <registry> <artifact-dir> <result-dir> <target-version>\n");
    exit(2);
}
[$script, $registry, $artifacts, $results, $target] = $argv;
$root = dirname(__DIR__, 3);
$entries = require $registry;

if (! is_array($entries) || $entries === []) {
    fwrite(STDERR, "UNAVAILABLE: no proven published baselines are registered.\n");
    exit(1);
}

if (! is_dir($artifacts) || ! is_dir($results)) {
    fwrite(STDERR, "UNAVAILABLE: artifact or result directory missing.\n");
    exit(1);
}
$required = ['baseline_installed', 'seeded_roles_grants_fields_morph_audit', 'target_installed',
    'migrations_twice', 'versions_match', 'doctor', 'allow_deny_parity', 'state_reset'];
$failed = false;
foreach ($entries as $entry) {
    if (! is_array($entry) || ! is_string($entry['id'] ?? null)
        || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/', $entry['id'])
        || ! is_string($entry['evidence'] ?? null) || ! str_starts_with($entry['evidence'], 'https://')
        || ! is_array($entry['sha256'] ?? null)) {
        fwrite(STDERR, "UNAVAILABLE: invalid baseline inventory entry.\n");
        $failed = true;

        continue;
    }
    $id = $entry['id'];
    $recipe = realpath($root.'/tests/Fixtures/Upgrade/baselines/'.$id.'/drill.sh');
    $recipeRoot = realpath($root.'/tests/Fixtures/Upgrade/baselines/'.$id);

    if ($recipe === false || $recipeRoot === false || dirname($recipe) !== $recipeRoot
        || ! str_starts_with($recipeRoot, $root.'/tests/Fixtures/Upgrade/baselines/')) {
        fwrite(STDERR, "UNAVAILABLE: missing reviewed fixture for $id.\n");
        $failed = true;

        continue;
    }
    $paths = [];
    foreach (['core', 'filament'] as $package) {
        $hash = $entry['sha256'][$package] ?? null;
        $path = realpath($artifacts.'/'.$id.'/'.$package.'.zip');
        $artifactRoot = realpath($artifacts);

        if (! is_string($hash) || ! preg_match('/\A[0-9a-f]{64}\z/', $hash) || $path === false
            || $artifactRoot === false || ! str_starts_with($path, $artifactRoot.'/')
            || ! hash_equals($hash, hash_file('sha256', $path))) {
            fwrite(STDERR, "UNAVAILABLE: missing or mismatched $id $package artifact.\n");
            $failed = true;

            continue 2;
        }
        $paths[$package] = $path;
    }
    $dir = $results.'/'.$id;

    if (file_exists($dir) || ! mkdir($dir, 0700)) {
        fwrite(STDERR, "UNAVAILABLE: result directory must be fresh for $id.\n");
        $failed = true;

        continue;
    }
    $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $dir, 'TMPDIR' => $dir,
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dir.'/authority_test.sqlite',
        'CACHE_STORE' => 'array', 'AZGUARD_UPGRADE_CORE' => $paths['core'],
        'AZGUARD_UPGRADE_FILAMENT' => $paths['filament'], 'AZGUARD_UPGRADE_DIR' => $dir,
        'AZGUARD_UPGRADE_TARGET' => $target];
    $process = proc_open(['bash', $recipe], [0 => ['file', '/dev/null', 'r'],
        1 => ['file', $dir.'/fixture.log', 'w'], 2 => ['redirect', 1]], $pipes, $dir, $env);
    $exit = is_resource($process) ? proc_close($process) : 1;
    $result = is_file($dir.'/result.json') ? json_decode((string) file_get_contents($dir.'/result.json'), true) : null;

    if ($exit !== 0 || ! is_array($result) || ($result['baseline'] ?? null) !== $id
        || ($result['target'] ?? null) !== $target) {
        fwrite(STDERR, "FAIL: $id fixture failed or returned invalid identity (exit $exit).\n");
        $failed = true;

        continue;
    }
    foreach ($required as $check) {
        if (($result['checks'][$check] ?? null) !== true) {
            fwrite(STDERR, "FAIL: $id missing or failed $check.\n");
            $failed = true;
        }
    }
}
exit($failed ? 1 : 0);
