<?php

declare(strict_types=1);

require_once __DIR__.'/trusted-core-producer-candidate.php';

$root = sys_get_temp_dir().'/azguard-trusted-source-'.bin2hex(random_bytes(8));

if (! mkdir($root.'/.git/hooks', 0700, true)) {
    throw new RuntimeException('cannot create untrusted repository fixture');
}

try {
    $marker = $root.'/executed';
    file_put_contents($root.'/.git/config', "[core]\n fsmonitor = touch $marker\n[include]\n path = $root/evil-config\n");
    file_put_contents($root.'/.git/hooks/pre-commit', "#!/bin/sh\ntouch $marker\n");
    chmod($root.'/.git/hooks/pre-commit', 0700);
    $identity = ['workflow_sha' => str_repeat('a', 40), 'source_sha' => str_repeat('b', 40),
        'split_sha' => str_repeat('c', 40), 'tag' => 'v2.0.0'];
    $approvedGit = ['path' => '/usr/bin/git', 'sha256' => hash_file('sha256', '/usr/bin/git')];
    $evilGit = $root.'/evil-git';
    file_put_contents($evilGit, "#!/bin/sh\ntouch $marker\n");
    chmod($evilGit, 0700);

    try {
        trustedProducerInspectSource($root, $identity, ['path' => $evilGit, 'sha256' => $approvedGit['sha256']]);

        throw new LogicException('unapproved Git executable accepted');
    } catch (RuntimeException) {
    }

    if (file_exists($marker)) {
        throw new RuntimeException('unapproved Git executable ran');
    }
    $originalTool = $root.'/original-tool';
    $originalLock = $root.'/original-lock';
    file_put_contents($originalTool, "#!/bin/sh\nprintf approved\n");
    chmod($originalTool, 0700);
    file_put_contents($originalLock, 'approved-lock');
    $staged = trustedProducerStageInputs([
        'git' => ['path' => $originalTool, 'sha256' => hash_file('sha256', $originalTool)],
        'lock' => ['path' => $originalLock, 'sha256' => hash_file('sha256', $originalLock)],
    ], ['git']);

    try {
        file_put_contents($originalTool, "#!/bin/sh\ntouch $marker\n");
        file_put_contents($originalLock, 'replaced-lock');

        if (trustedProducerOfflineCommand([$staged['inputs']['git']['path']], $root) !== 'approved'
            || file_get_contents($staged['inputs']['lock']['path']) !== 'approved-lock' || file_exists($marker)) {
            throw new RuntimeException('staged inputs changed after original replacement');
        }

        try {
            trustedProducerStageInputs(['lock' => ['path' => $originalLock, 'sha256' => str_repeat('f', 64)]], []);

            throw new LogicException('mismatched staged input accepted');
        } catch (RuntimeException) {
        }
    } finally {
        packageRemoveFixtureDirectory($staged['directory']);
    }
    $previous = getenv('UNRECOGNIZED_CREDENTIAL');
    putenv('UNRECOGNIZED_CREDENTIAL=secret-fixture');

    try {
        try {
            trustedProducerInspectSource($root, $identity, $approvedGit);

            throw new LogicException('unsafe source inspection succeeded');
        } catch (RuntimeException $exception) {
            // Expected fail-closed rejection.
        }
        mkdir($root.'/output', 0700);
        $inputs = [];
        foreach (['composer', 'dependency_sbom', 'git', 'lock', 'normalizer', 'python', 'tar', 'validator'] as $name) {
            $inputs[$name] = ['path' => '/usr/bin/true', 'sha256' => hash_file('sha256', '/usr/bin/true')];
        }

        try {
            trustedProducerBuildOffline($root, $identity, $inputs, $root.'/output',
                ['run_id' => '1', 'run_attempt' => '1']);

            throw new LogicException('unsafe offline build succeeded');
        } catch (RuntimeException $exception) {
            // Expected fail-closed rejection.
        }
    } finally {
        $previous === false ? putenv('UNRECOGNIZED_CREDENTIAL') : putenv('UNRECOGNIZED_CREDENTIAL='.$previous);
    }

    if (file_exists($marker)) {
        throw new RuntimeException('untrusted config or hook executed');
    }
    echo "Unsafe source inspection and build are rejected before source Git execution.\n";
} finally {
    packageRemoveFixtureDirectory($root);
}
