<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Console\GeneratedApp;
use Packman\DevTools\Scaffold\GeneratedFile;
use Packman\DevTools\Scaffold\ScaffoldCollisionException;
use Packman\DevTools\Scaffold\ScaffoldRunner;

beforeEach(function (): void {
    $this->generated = GeneratedApp::in(app());
});
afterEach(fn () => $this->generated->release());

it('rolls back Packman generated files when a later atomic rename fails', function (): void {
    $root = $this->generated->root;
    file_put_contents($root.'/existing.php', 'owner code');
    chmod($root.'/existing.php', 0o640);
    mkdir($root.'/blocked.php');
    $planned = [
        ['target' => $root.'/existing.php', 'file' => new GeneratedFile('existing.php', 'replacement', overwrite: true)],
        ['target' => $root.'/new.php', 'file' => new GeneratedFile('new.php', 'new code')],
        ['target' => $root.'/blocked.php', 'file' => new GeneratedFile('blocked.php', 'unwritable')],
    ];

    expect(fn () => (new ScaffoldRunner)->write($planned))->toThrow(RuntimeException::class, 'Unable to move scaffold file into place')
        ->and(file_get_contents($root.'/existing.php'))->toBe('owner code')
        ->and(fileperms($root.'/existing.php') & 0o777)->toBe(0o640)
        ->and(is_file($root.'/new.php'))->toBeFalse()
        ->and(glob($root.'/.packman-scaffold-*'))->toBe([]);
});

it('cleans staged files without changing earlier targets when a Packman file refuses overwrite', function (): void {
    $root = $this->generated->root;
    file_put_contents($root.'/existing.php', 'owner code');
    $planned = [
        ['target' => $root.'/new.php', 'file' => new GeneratedFile('new.php', 'new code')],
        ['target' => $root.'/existing.php', 'file' => new GeneratedFile('existing.php', 'replacement')],
    ];

    expect(fn () => (new ScaffoldRunner)->write($planned))->toThrow(ScaffoldCollisionException::class, 'already exists')
        ->and(file_get_contents($root.'/existing.php'))->toBe('owner code')
        ->and(is_file($root.'/new.php'))->toBeFalse()
        ->and(glob($root.'/.packman-scaffold-*'))->toBe([]);
});
