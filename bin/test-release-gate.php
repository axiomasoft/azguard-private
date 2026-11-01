<?php

declare(strict_types=1);

require __DIR__.'/release-gate.php';

$sha = str_repeat('a', 40);
$evidence = [
    'sha' => $sha,
    'tag' => 'v2.0.0-rc.1',
    'tag_sha' => $sha,
    'run_id' => '123-1',
    'jobs' => [],
    'matrix' => [],
    'scanners' => [],
    'advisories' => [],
    'split_prerequisites' => ['archives' => true, 'sboms' => true, 'consumer_tests' => true],
];
$lock = '{"packages":[],"packages-dev":[]}';
foreach (['tests', 'static', 'style', 'docs', 'nightly', 'compat', 'workers', 'security', 'codeql', 'zizmor', 'secret-scan', 'validate', 'validate-consumers', 'artifacts', 'advisories'] as $job) {
    $evidence['jobs'][$job] = ['result' => 'success'];
}
foreach (['8.3', '8.4', '8.5'] as $php) {
    foreach (['12', '13'] as $laravel) {
        if ($php === '8.5' && $laravel === '12') {
            continue;
        }
        foreach (['prefer-lowest', 'prefer-stable'] as $stability) {
            $evidence['matrix'][] = ['name' => "php{$php}-laravel{$laravel}-{$stability}", 'sha' => $sha, 'run_id' => '123-1', 'result' => 'success', 'lock' => $lock, 'lock_sha256' => hash('sha256', $lock)];
        }
    }
}
foreach (['pgsql', 'mysql', 'mariadb'] as $db) {
    foreach (['12', '13'] as $laravel) {
        $evidence['matrix'][] = ['name' => "db-{$db}-laravel{$laravel}", 'sha' => $sha, 'run_id' => '123-1', 'result' => 'success', 'lock' => $lock, 'lock_sha256' => hash('sha256', $lock)];
    }
}
foreach (['codeql-actions', 'codeql-javascript-typescript', 'zizmor', 'gitleaks-worktree', 'gitleaks-history'] as $scanner) {
    $run = ['tool' => ['driver' => ['name' => str_starts_with($scanner, 'codeql-') ? 'CodeQL' : 'zizmor']], 'results' => []];

    if (str_starts_with($scanner, 'codeql-')) {
        $run['automationDetails'] = ['id' => '/language:'.substr($scanner, 7).'/'];
    }
    $report = str_starts_with($scanner, 'gitleaks-') ? '[]' : json_encode(['version' => '2.1.0', 'runs' => [$run]], JSON_THROW_ON_ERROR);
    $evidence['scanners'][] = ['scanner' => $scanner, 'sha' => $sha, 'run_id' => '123-1', 'outcome' => 'success', 'errors' => [], 'report' => $report, 'report_sha256' => hash('sha256', $report)];
}
foreach ([
    'composer' => ['advisories' => [], 'abandoned' => []],
    'npm' => ['auditReportVersion' => 2, 'vulnerabilities' => [], 'metadata' => ['vulnerabilities' => ['total' => 0]]],
    'osv' => ['results' => []],
] as $audit => $body) {
    $report = json_encode($body, JSON_THROW_ON_ERROR);
    $evidence['advisories'][] = ['audit' => $audit, 'sha' => $sha, 'run_id' => '123-1', 'outcome' => 'success', 'report' => $report, 'report_sha256' => hash('sha256', $report)];
}

$expect = static function (array $input, ?string $message): void {
    $errors = releaseGateErrors($input);

    if ($message === null ? $errors !== [] : ! str_contains(implode("\n", $errors), $message)) {
        fwrite(STDERR, 'unexpected gate result: '.json_encode($errors)."\n");
        exit(1);
    }
};
$expect($evidence, null);

// Exercise the producer-to-gate boundary using the on-disk artifact layout.
$root = sys_get_temp_dir().'/azguard-release-evidence-'.bin2hex(random_bytes(8));
mkdir($root);

try {
    foreach ($evidence['matrix'] as $row) {
        $dir = $root.'/release-row-1-'.$row['name'];
        mkdir($dir);
        file_put_contents($dir.'/composer.lock', $row['lock']);
        unset($row['lock'], $row['lock_sha256']);
        file_put_contents($dir.'/row.json', json_encode($row, JSON_THROW_ON_ERROR));
    }
    foreach ($evidence['scanners'] as $row) {
        $scanner = $row['scanner'];
        $artifact = match (true) {
            str_starts_with($scanner, 'codeql-') => $scanner.'-'.$sha,
            str_starts_with($scanner, 'gitleaks-') => 'secret-scan-'.substr($scanner, 9).'-'.$sha,
            default => 'zizmor-'.$sha,
        };
        $dir = $root.'/'.$artifact.'-1';
        mkdir($dir);
        file_put_contents($dir.'/report.'.(str_starts_with($scanner, 'gitleaks-') ? 'json' : 'sarif'), $row['report']);
        unset($row['report'], $row['run_id']);
        file_put_contents($dir.'/metadata.json', json_encode($row, JSON_THROW_ON_ERROR));
    }
    $dir = $root.'/release-advisories-1';
    mkdir($dir);
    file_put_contents($dir.'/metadata.json', json_encode(['sha' => $sha, 'run_id' => '123-1', 'outcomes' => ['composer' => 'success', 'npm' => 'success', 'osv' => 'success']], JSON_THROW_ON_ERROR));
    foreach ($evidence['advisories'] as $row) {
        file_put_contents($dir.'/'.$row['audit'].'.json', $row['report']);
    }
    $collect = static function (string $runId) use ($root, $sha, $evidence): array {
        $process = proc_open([PHP_BINARY, __DIR__.'/collect-release-evidence.php', $root, $sha, 'v2.0.0-rc.1', $sha, $runId], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['JOBS' => json_encode($evidence['jobs'], JSON_THROW_ON_ERROR)]);

        if (! is_resource($process)) {
            throw new RuntimeException('collector did not start');
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new RuntimeException('collector failed: '.$err);
        }

        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    };
    $expect($collect('123-1'), null);
    $expect($collect('123-2'), 'missing matrix row');
    unlink($root.'/release-row-1-'.$evidence['matrix'][0]['name'].'/composer.lock');
    $expect($collect('123-1'), 'missing matrix row');
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
foreach (['skipped', 'cancelled', 'failure'] as $result) {
    $bad = $evidence;
    $bad['jobs']['tests']['result'] = $result;
    $expect($bad, "job tests: {$result}");
}
$bad = $evidence;
$bad['matrix'][0]['sha'] = str_repeat('c', 40);
$expect($bad, 'stale matrix row');
$bad = $evidence;
$bad['matrix'][0]['run_id'] = '122';
$expect($bad, 'stale matrix row');
$bad = $evidence;
array_pop($bad['matrix']);
$expect($bad, 'missing matrix row');
$bad = $evidence;
$bad['matrix'][] = $bad['matrix'][0];
$expect($bad, 'duplicate matrix row');
$bad = $evidence;
unset($bad['matrix'][0]['lock_sha256']);
$expect($bad, 'missing lock hash');
$bad = $evidence;
$bad['matrix'][0]['lock'] = '{}';
$expect($bad, 'lock digest mismatch');
$bad = $evidence;
$bad['matrix'][0]['result'] = 'skipped';
$expect($bad, 'matrix row php8.3-laravel12-prefer-lowest: skipped');
$bad = $evidence;
$bad['scanners'][0]['outcome'] = 'failure';
$expect($bad, 'scanner codeql-actions outcome: failure');
$bad = $evidence;
$bad['scanners'][0]['errors'] = ['analysis unavailable'];
$expect($bad, 'analysis unavailable');
$bad = $evidence;
$bad['scanners'][0]['report'] = 'advisory finding';
$expect($bad, 'report digest mismatch');
$bad = $evidence;
$bad['scanners'][3]['report'] = '[{"finding":"secret"}]';
$bad['scanners'][3]['report_sha256'] = hash('sha256', $bad['scanners'][3]['report']);
$expect($bad, 'contains findings');
$bad = $evidence;
$bad['scanners'][] = $bad['scanners'][0];
$expect($bad, 'duplicate scanner');
foreach ([
    0 => ['advisories' => ['vendor/package' => [['title' => 'CVE fixture']]], 'abandoned' => []],
    1 => ['auditReportVersion' => 2, 'vulnerabilities' => ['package' => ['severity' => 'high']], 'metadata' => ['vulnerabilities' => ['total' => 1]]],
    2 => ['results' => [['packages' => [['vulnerabilities' => [['id' => 'OSV fixture']]]]]]],
] as $index => $finding) {
    $bad = $evidence;
    $bad['advisories'][$index]['report'] = json_encode($finding, JSON_THROW_ON_ERROR);
    $bad['advisories'][$index]['report_sha256'] = hash('sha256', $bad['advisories'][$index]['report']);
    $expect($bad, 'contains findings or an invalid report');
    $expect($bad, ['CVE fixture', 'high', 'OSV fixture'][$index]);
}
$bad = $evidence;
$bad['advisories'][0]['outcome'] = 'cancelled';
$expect($bad, 'advisory composer outcome: cancelled');
$bad = $evidence;
$bad['advisories'][1]['sha'] = str_repeat('c', 40);
$expect($bad, 'stale advisory npm');
$bad = $evidence;
array_pop($bad['advisories']);
$expect($bad, 'missing advisory osv');
$bad = $evidence;
$bad['advisories'][] = $bad['advisories'][0];
$expect($bad, 'duplicate advisory composer');
$bad = $evidence;
$bad['advisories'][0]['report'] = '{}';
$expect($bad, 'advisory composer report digest mismatch');
$bad = $evidence;
$bad['tag_sha'] = str_repeat('c', 40);
$expect($bad, 'tag does not resolve');
$bad = $evidence;
$bad['split_prerequisites']['sboms'] = false;
$expect($bad, 'missing split prerequisites');
$expect([], 'invalid candidate SHA');
fwrite(STDOUT, "release gate fixtures passed\n");
