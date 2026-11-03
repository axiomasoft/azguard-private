<?php

declare(strict_types=1);

require_once __DIR__.'/trusted-core-producer-candidate.php';

$root = sys_get_temp_dir().'/azguard-trusted-source-'.bin2hex(random_bytes(8));
$repository = $root.'/repo';
if (! mkdir($repository.'/packages/core/.github', 0700, true)) {
    throw new RuntimeException('cannot create source fixture');
}
try {
    file_put_contents($repository.'/packages/core/composer.json', '{"name":"axiomasoft/azguard"}');
    // This file is deliberately attacker-controlled data and must never run.
    file_put_contents($repository.'/packages/core/.github/verify-release-source.sh', "#!/bin/sh\ntouch '$root/executed'\n");
    $git = static fn (string ...$args): string => trim(packageEvidenceCommand(['git', '-C', $repository, ...$args]));
    $git('init', '-q');
    $git('add', 'packages');
    $git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'fixture');
    $source = $git('rev-parse', 'HEAD');
    $split = $git('subtree', 'split', '--prefix=packages/core', $source);
    $approved = ['workflow_sha' => str_repeat('a', 40), 'source_sha' => $source,
        'split_sha' => $split, 'tag' => 'v2.0.0'];
    $called = false;
    $verify = static function (string $repo, string $tag, string $sha) use (&$called, $repository, $source): void {
        if ($repo !== realpath($repository) || $tag !== 'v2.0.0' || $sha !== $source) {
            throw new RuntimeException('unexpected signed tag identity');
        }
        $called = true;
    };
    $result = trustedProducerInspectSource($repository, $approved, $verify);
    if (! $called || ($result['mode'] ?? null) !== 'offline-candidate-only' || file_exists($root.'/executed')) {
        throw new RuntimeException('untrusted source script executed or inspection incomplete');
    }
    $reject = static function (callable $case): void {
        try {
            $case();
        } catch (RuntimeException) {
            return;
        }
        throw new RuntimeException('unsafe source accepted');
    };
    $bad = $approved;
    $bad['split_sha'] = str_repeat('f', 40);
    $reject(static fn () => trustedProducerInspectSource($repository, $bad, $verify));
    $reject(static fn () => trustedProducerInspectSource($repository, $approved, static function (): void {
        throw new RuntimeException('bad tag signature');
    }));
    $bad = $approved;
    $bad['workflow_sha'] = 'main';
    $reject(static fn () => trustedProducerInspectSource($repository, $bad, $verify));
    $process = proc_open([PHP_BINARY, __DIR__.'/trusted-core-producer-candidate.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($process) || proc_close($process) !== 1 || file_exists($root.'/executed')) {
        throw new RuntimeException('candidate is executable or source code ran');
    }
    echo "Trusted producer offline source fixtures passed; live producer remains disabled.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
