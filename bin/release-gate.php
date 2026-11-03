<?php

declare(strict_types=1);

const EXPECTED_GITLEAKS_CONFIG_SHA256 = '72d02105f6cb4acddfe9cb1b105d1db4814b21d62b639e608194aa38590ae01c';

/**
 * Validate Gitleaks configuration digest, product-code allowlist restrictions, and .gitleaksignore absence.
 *
 * @return list<string>
 */
function gitleaksPolicyErrors(?string $root = null, string $expectedSha256 = EXPECTED_GITLEAKS_CONFIG_SHA256): array
{
    $root ??= dirname(__DIR__);
    $errors = [];

    if (file_exists($root.'/.gitleaksignore') || is_link($root.'/.gitleaksignore')) {
        $errors[] = 'forbidden .gitleaksignore present';
    }
    $config = @file_get_contents($root.'/.gitleaks.toml');

    if (! is_string($config)) {
        $errors[] = 'missing .gitleaks.toml';

        return $errors;
    }

    if (preg_match('/^\[allowlist\]\R([\s\S]*?)(?=^\[\[rules\]\]|\z)/m', $config, $section) === 1
        && preg_match('/^paths\s*=\s*\[([\s\S]*?)^\]/m', $section[1], $pathsBlock) === 1) {
        preg_match_all("/'''([^']*)'''|\"([^\"]*)\"/", $pathsBlock[1], $matches);
        $patterns = array_values(array_filter([...$matches[1], ...$matches[2]], static fn (string $v): bool => $v !== ''));
        $forbiddenPrefixes = ['packages/', 'src/', 'config/', '.github/workflows/'];
        $samples = ['packages/core/src/AzGuard.php', 'src/AzGuard.php', 'config/azguard.php', '.github/workflows/security.yml'];

        foreach ($patterns as $pattern) {
            foreach ($forbiddenPrefixes as $prefix) {
                if (str_contains($pattern, $prefix)) {
                    $errors[] = 'gitleaks allowlist excludes product code';

                    break 2;
                }
            }
            foreach ($samples as $sample) {
                if (@preg_match('~'.$pattern.'~', $sample) === 1) {
                    $errors[] = 'gitleaks allowlist excludes product code';

                    break 2;
                }
            }
        }
    }

    if (! hash_equals($expectedSha256, hash('sha256', $config))) {
        $errors[] = 'unexpected .gitleaks.toml digest';
    }

    return $errors;
}

/**
 * Evaluate a single release run. The caller must collect evidence from that run;
 * missing or malformed evidence is an error, never an implicit success.
 *
 * @param  array<string, mixed>  $data
 * @return list<string>
 */
function releaseGateErrors(array $data): array
{
    $errors = gitleaksPolicyErrors(is_string($data['root'] ?? null) ? $data['root'] : null);
    $sha = $data['sha'] ?? null;

    if (! is_string($sha) || preg_match('/\A[0-9a-f]{40}\z/', $sha) !== 1) {
        $errors[] = 'invalid candidate SHA';
    }

    if (! is_string($data['run_id'] ?? null) || preg_match('/\A[1-9][0-9]*-[1-9][0-9]*\z/', $data['run_id']) !== 1) {
        $errors[] = 'invalid run ID';
    }

    if (! is_string($data['tag'] ?? null) || preg_match('/\Av[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/', $data['tag']) !== 1 || ($data['tag_sha'] ?? null) !== $sha) {
        $errors[] = 'tag does not resolve to candidate SHA';
    }

    $jobs = ['tests', 'static', 'style', 'docs', 'nightly', 'compat', 'workers', 'security', 'codeql', 'zizmor', 'secret-scan', 'validate', 'validate-consumers', 'artifacts', 'advisories'];
    $results = $data['jobs'] ?? null;

    if (! is_array($results)) {
        $errors[] = 'missing job results';
        $results = [];
    }
    foreach ($jobs as $job) {
        $result = $results[$job]['result'] ?? null;

        if ($result !== 'success') {
            $errors[] = "job {$job}: ".(is_string($result) ? $result : 'missing');
        }
    }

    $expected = [];
    foreach (['8.3', '8.4', '8.5'] as $php) {
        foreach (['12', '13'] as $laravel) {
            if ($php === '8.5' && $laravel === '12') {
                continue;
            }
            foreach (['prefer-lowest', 'prefer-stable'] as $stability) {
                $expected[] = "php{$php}-laravel{$laravel}-{$stability}";
            }
        }
    }
    foreach (['pgsql', 'mysql', 'mariadb'] as $db) {
        foreach (['12', '13'] as $laravel) {
            $expected[] = "db-{$db}-laravel{$laravel}";
        }
    }
    $rows = $data['matrix'] ?? null;

    if (! is_array($rows) || ! array_is_list($rows)) {
        $errors[] = 'missing matrix evidence';
        $rows = [];
    }
    $seen = [];
    foreach ($rows as $row) {
        if (! is_array($row) || ! is_string($row['name'] ?? null) || ! in_array($row['name'], $expected, true)) {
            $errors[] = 'unknown matrix row';

            continue;
        }
        $name = $row['name'];

        if (isset($seen[$name])) {
            $errors[] = "duplicate matrix row {$name}";
        }
        $seen[$name] = true;

        if (($row['sha'] ?? null) !== $sha || ($row['run_id'] ?? null) !== ($data['run_id'] ?? null)) {
            $errors[] = "stale matrix row {$name}";
        }

        if (($row['result'] ?? null) !== 'success') {
            $errors[] = "matrix row {$name}: ".(is_string($row['result'] ?? null) ? $row['result'] : 'missing');
        }

        if (($row['dependency_audit_passed'] ?? false) !== true) {
            $errors[] = "missing dependency audit for {$name}";
        }
        $lock = $row['lock'] ?? null;

        if (! is_string($row['lock_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/', $row['lock_sha256']) !== 1) {
            $errors[] = "missing lock hash for {$name}";
        } elseif (! is_string($lock) || ! hash_equals($row['lock_sha256'], hash('sha256', $lock))) {
            $errors[] = "lock digest mismatch for {$name}";
        } else {
            try {
                $decoded = json_decode($lock, true, 512, JSON_THROW_ON_ERROR);

                if (! is_array($decoded) || ! is_array($decoded['packages'] ?? null) || ! is_array($decoded['packages-dev'] ?? null)) {
                    $errors[] = "invalid lock for {$name}";
                }
            } catch (JsonException) {
                $errors[] = "invalid lock for {$name}";
            }
        }
    }
    foreach (array_diff($expected, array_keys($seen)) as $name) {
        $errors[] = "missing matrix row {$name}";
    }

    $scopes = [
        'codeql-actions' => 'CodeQL Actions extraction across the checked-out repository',
        'codeql-javascript-typescript' => 'CodeQL JavaScript/TypeScript extraction across the checked-out repository, including docs/.vitepress/config.ts',
        'zizmor' => 'GitHub Actions workflows in .github/workflows and packages/core/.github/workflows; pedantic, medium and higher',
        'gitleaks-worktree' => 'Gitleaks directory scan of the checked-out worktree using the pinned rules without allowlists',
        'gitleaks-history' => 'Gitleaks git patch scan of all commits reachable from the checked-out commit, including merge changes, using the pinned rules without allowlists',
    ];
    $scanners = array_keys($scopes);
    $reports = $data['scanners'] ?? null;

    if (! is_array($reports) || ! array_is_list($reports)) {
        $errors[] = 'missing scanner evidence';
        $reports = [];
    }
    $seen = [];
    foreach ($reports as $report) {
        if (! is_array($report) || ! is_string($report['scanner'] ?? null) || ! in_array($report['scanner'], $scanners, true)) {
            $errors[] = 'unknown scanner evidence';

            continue;
        }
        $name = $report['scanner'];

        if (isset($seen[$name])) {
            $errors[] = "duplicate scanner {$name}";
        }
        $seen[$name] = true;

        if (($report['scope'] ?? null) !== $scopes[$name]) {
            $errors[] = "scanner {$name} has invalid scope";
        }

        if (($report['sha'] ?? null) !== $sha || ($report['run_id'] ?? null) !== ($data['run_id'] ?? null)) {
            $errors[] = "stale scanner {$name}";
        }

        if (($report['outcome'] ?? null) !== 'success') {
            $outcome = $report['outcome'] ?? 'missing';
            $errors[] = "scanner {$name} outcome: ".(is_string($outcome) ? $outcome : 'invalid');
        }

        if (($report['errors'] ?? null) !== []) {
            $detail = is_array($report['errors'] ?? null) ? implode('; ', array_filter($report['errors'], is_string(...))) : 'missing errors';
            $errors[] = "scanner {$name} errors: {$detail}";
        }
        $contents = $report['report'] ?? null;

        if (! is_string($contents) || ! is_string($report['report_sha256'] ?? null) || ! hash_equals($report['report_sha256'], hash('sha256', $contents))) {
            $errors[] = "scanner {$name} report digest mismatch";

            continue;
        }

        try {
            $shape = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            $parsed = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            if (str_starts_with($name, 'gitleaks-')) {
                if (! is_array($shape) || $shape !== []) {
                    $errors[] = "scanner {$name} contains findings";
                }
            } elseif (! is_object($shape) || ($parsed['version'] ?? null) !== '2.1.0' || ! is_array($shape->runs ?? null) || $parsed['runs'] === []) {
                $errors[] = "scanner {$name} has invalid SARIF";
            } else {
                foreach ($parsed['runs'] as $index => $run) {
                    $runShape = $shape->runs[$index];

                    if (! is_object($runShape) || ! is_array($runShape->results ?? null) || ($run['results'] ?? null) !== []) {
                        $errors[] = "scanner {$name} contains findings or invalid results";

                        continue;
                    }
                    $tool = $run['tool']['driver']['name'] ?? null;
                    $expectedTool = str_starts_with($name, 'codeql-') ? 'codeql' : 'zizmor';

                    if (! is_string($tool) || ! str_contains(strtolower($tool), $expectedTool)) {
                        $errors[] = "scanner {$name} has unexpected SARIF tool";
                    }

                    if (str_starts_with($name, 'codeql-') && ($run['automationDetails']['id'] ?? null) !== '/language:'.substr($name, 7).'/') {
                        $errors[] = "scanner {$name} has incorrect SARIF category";
                    }
                    $invocations = $run['invocations'] ?? null;

                    if (! is_array($runShape->invocations ?? null) || ! is_array($invocations) || ! array_is_list($invocations) || $invocations === []) {
                        $errors[] = "scanner {$name} has invalid invocations";

                        continue;
                    }
                    foreach ($invocations as $invocationIndex => $invocation) {
                        if (! is_object($runShape->invocations[$invocationIndex]) || ! is_array($invocation) || ($invocation['executionSuccessful'] ?? null) !== true) {
                            $errors[] = "scanner {$name} invocation failed";

                            continue;
                        }

                        if (array_key_exists('exitCode', $invocation) && (! is_int($invocation['exitCode']) || $invocation['exitCode'] !== 0)) {
                            $errors[] = "scanner {$name} has invalid invocation exit code";
                        }
                        foreach (['toolExecutionNotifications', 'toolConfigurationNotifications'] as $key) {
                            $notifications = $invocation[$key] ?? [];

                            if ((property_exists($runShape->invocations[$invocationIndex], $key) && ! is_array($runShape->invocations[$invocationIndex]->{$key})) || ! is_array($notifications)) {
                                $errors[] = "scanner {$name} has invalid notifications";

                                continue;
                            }
                            foreach ($notifications as $notificationIndex => $notification) {
                                if (! is_object($runShape->invocations[$invocationIndex]->{$key}[$notificationIndex]) || ! is_array($notification) || ! in_array(array_key_exists('level', $notification) ? $notification['level'] : 'warning', ['none', 'note', 'warning'], true)) {
                                    $errors[] = "scanner {$name} has error notification";
                                }
                            }
                        }
                    }
                }
            }
        } catch (JsonException) {
            $errors[] = "scanner {$name} report is not JSON";
        }
    }
    foreach (array_diff($scanners, array_keys($seen)) as $name) {
        $errors[] = "missing scanner {$name}";
    }

    // A successful audit job alone does not prove which advisory report was
    // evaluated. Require the original, digest-bound report from each tool.
    $audits = ['composer', 'npm', 'osv'];
    $reports = $data['advisories'] ?? null;

    if (! is_array($reports) || ! array_is_list($reports)) {
        $errors[] = 'missing advisory evidence';
        $reports = [];
    }
    $seen = [];
    foreach ($reports as $report) {
        $name = $report['audit'] ?? null;

        if (! is_array($report) || ! is_string($name) || ! in_array($name, $audits, true)) {
            $errors[] = 'unknown advisory evidence';

            continue;
        }

        if (isset($seen[$name])) {
            $errors[] = "duplicate advisory {$name}";
        }
        $seen[$name] = true;

        if (($report['sha'] ?? null) !== $sha || ($report['run_id'] ?? null) !== ($data['run_id'] ?? null)) {
            $errors[] = "stale advisory {$name}";
        }

        if (($report['outcome'] ?? null) !== 'success') {
            $outcome = $report['outcome'] ?? 'missing';
            $errors[] = "advisory {$name} outcome: ".(is_string($outcome) ? $outcome : 'invalid');
        }
        $contents = $report['report'] ?? null;

        if (! is_string($contents) || ! is_string($report['report_sha256'] ?? null) || ! hash_equals($report['report_sha256'], hash('sha256', $contents))) {
            $errors[] = "advisory {$name} report digest mismatch";

            continue;
        }

        try {
            $parsed = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            $clean = match ($name) {
                'composer' => is_array($parsed) && ($parsed['advisories'] ?? null) === [] && ($parsed['abandoned'] ?? null) === [],
                'npm' => is_array($parsed) && ($parsed['auditReportVersion'] ?? null) === 2
                    && ($parsed['vulnerabilities'] ?? null) === []
                    && is_array($parsed['metadata']['vulnerabilities'] ?? null)
                    && ($parsed['metadata']['vulnerabilities']['total'] ?? null) === 0,
                'osv' => is_array($parsed) && ($parsed['results'] ?? null) === [],
            };

            if (! $clean) {
                $detail = json_encode($parsed, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                $errors[] = "advisory {$name} contains findings or an invalid report: ".substr(is_string($detail) ? $detail : 'unavailable', 0, 2048);
            }
        } catch (JsonException) {
            $errors[] = "advisory {$name} report is not JSON";
        }
    }
    foreach (array_diff($audits, array_keys($seen)) as $name) {
        $errors[] = "missing advisory {$name}";
    }

    if (($data['split_prerequisites'] ?? null) !== ['archives' => true, 'sboms' => true, 'consumer_tests' => true]) {
        $errors[] = 'missing split prerequisites';
    }

    return $errors;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        if ($argc !== 2) {
            throw new RuntimeException('usage: php bin/release-gate.php evidence.json');
        }
        $raw = file_get_contents($argv[1]);

        if ($raw === false) {
            throw new RuntimeException('cannot read evidence');
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException('evidence must be an object');
        }
        $errors = releaseGateErrors($data);
        foreach ($errors as $error) {
            fwrite(STDERR, "release gate: {$error}\n");
        }
        exit($errors === [] ? 0 : 1);
    } catch (Throwable $e) {
        fwrite(STDERR, 'release gate: '.$e->getMessage()."\n");
        exit(1);
    }
}
