<?php

declare(strict_types=1);

// All artifacts are downloaded by the Actions download-artifact action from this run.
// Missing or malformed files remain missing evidence; the evaluator explains the refusal.
if ($argc !== 6) {
    fwrite(STDERR, "usage: collect-release-evidence.php artifacts sha tag tag-sha run-id\n");
    exit(2);
}
[$script, $root, $sha, $tag, $tagSha, $runId] = $argv;
$jobs = json_decode(getenv('JOBS') ?: '', true, 512, JSON_THROW_ON_ERROR);
$data = ['sha' => $sha, 'tag' => $tag, 'tag_sha' => $tagSha, 'run_id' => $runId, 'jobs' => $jobs, 'matrix' => [], 'scanners' => [], 'advisories' => []];
$attempt = substr(strrchr($runId, '-') ?: '', 1);
foreach (glob($root.'/release-row-'.$attempt.'-*', GLOB_ONLYDIR) ?: [] as $dir) {
    $row = json_decode(@file_get_contents($dir.'/row.json') ?: '', true);
    $lock = @file_get_contents($dir.'/composer.lock');

    if (is_array($row) && is_string($lock) && basename($dir) === 'release-row-'.$attempt.'-'.($row['name'] ?? '')) {
        $row['lock'] = $lock;
        $row['lock_sha256'] = hash('sha256', $lock);
        $data['matrix'][] = $row;
    }
}
foreach (['codeql-actions', 'codeql-javascript-typescript', 'zizmor', 'gitleaks-worktree', 'gitleaks-history'] as $scanner) {
    $artifact = match (true) {
        str_starts_with($scanner, 'codeql-') => $scanner.'-'.$sha,
        str_starts_with($scanner, 'gitleaks-') => 'secret-scan-'.substr($scanner, 9).'-'.$sha,
        default => 'zizmor-'.$sha,
    };
    $dir = $root.'/'.$artifact.'-'.$attempt;
    $metadata = json_decode(@file_get_contents($dir.'/metadata.json') ?: '', true);
    $reports = glob($dir.'/*.'.(str_starts_with($scanner, 'gitleaks-') ? 'json' : 'sarif')) ?: [];
    $reports = array_values(array_filter($reports, static fn (string $file): bool => basename($file) !== 'metadata.json'));

    if (is_array($metadata) && count($reports) === 1 && ($metadata['scanner'] ?? null) === $scanner) {
        $contents = file_get_contents($reports[0]);

        if (is_string($contents)) {
            $metadata['run_id'] = $runId;
            $metadata['report'] = $contents;
            $data['scanners'][] = $metadata;
        }
    }
}
$dir = $root.'/release-advisories-'.$attempt;
$metadata = json_decode(@file_get_contents($dir.'/metadata.json') ?: '', true);

if (is_array($metadata)) {
    foreach (['composer', 'npm', 'osv'] as $audit) {
        $report = @file_get_contents($dir.'/'.$audit.'.json');

        if (is_string($report)) {
            $data['advisories'][] = ['audit' => $audit, 'sha' => $metadata['sha'] ?? null, 'run_id' => $metadata['run_id'] ?? null, 'outcome' => $metadata['outcomes'][$audit] ?? null, 'report' => $report, 'report_sha256' => hash('sha256', $report)];
        }
    }
}
$data['split_prerequisites'] = [
    'archives' => ($jobs['artifacts']['result'] ?? null) === 'success',
    'sboms' => ($jobs['artifacts']['result'] ?? null) === 'success',
    'consumer_tests' => ($jobs['validate-consumers']['result'] ?? null) === 'success',
];
echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
