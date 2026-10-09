<?php

declare(strict_types=1);

use AzGuard\Tests\Fixtures\Console\GeneratedApp;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->generated = GeneratedApp::in(app());
});
afterEach(fn () => $this->generated->release());

/** Everything the generators make, in one application. */
function generateEverything(GeneratedApp $generated): void
{
    $steps = [
        ['azguard:make:panel', ['panel' => 'Admin', '--model' => User::class]],
        ['azguard:make:permission', ['panel' => 'Admin', 'group' => 'Sales/Orders', '--policy' => true, '--abilities' => true, '--model' => User::class]],
        ['azguard:make:permission', ['panel' => 'Admin', 'group' => 'Users']],
        ['azguard:make:policy', ['panel' => 'Admin', 'group' => 'Users']],
        ['azguard:make:role', ['panel' => 'Admin', 'name' => 'SalesManager']],
        ['azguard:make:source', ['name' => 'Ldap', '--panel' => 'Admin', '--grants' => true, '--permissions' => true, '--roles' => true, '--policies' => true]],
        ['azguard:make:source', ['name' => 'Plain', '--shared' => true]],
        ['azguard:make:plugin', ['name' => 'AuditTrail', '--shared' => true]],
        ['azguard:make:restriction', ['name' => 'AccountLocked', '--panel' => 'Admin']],
        ['azguard:make:pipe', ['name' => 'RequireReason', '--panel' => 'Admin']],
        ['azguard:make:models', ['panel' => 'Admin']],
    ];

    foreach ($steps as [$command, $arguments]) {
        expect(Artisan::call($command, $arguments))->toBe(0, $command.': '.Artisan::output());
    }
}

/** @return array{int, string} */
function runTool(string $command): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 4));
    $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);

    return [proc_close($process), $output];
}

it('gives code Pint has nothing to change in', function (): void {
    generateEverything($this->generated);

    [$exit, $output] = runTool(PHP_BINARY.' vendor/bin/pint --test --config pint.json '.escapeshellarg($this->generated->path('app')).' '.escapeshellarg($this->generated->path('database')));

    expect($exit)->toBe(0, $output);
});

it('gives code PHPStan accepts at the level of the project', function (): void {
    generateEverything($this->generated);
    $project = dirname(__DIR__, 4);
    file_put_contents($this->generated->path('phpstan.neon'), "includes:\n  - ".$project."/vendor/larastan/larastan/extension.neon\n\nparameters:\n  paths:\n    - "
        .$this->generated->path('app')."\n    - ".$this->generated->path('database')."\n  scanDirectories:\n    - ".$this->generated->path('app')
        ."\n  level: 9\n  checkModelProperties: true\n  checkPhpDocMissingReturn: true\n  tmpDir: ".$this->generated->path('phpstan-cache')."\n");

    [$exit, $output] = runTool(PHP_BINARY.' vendor/bin/phpstan analyse --memory-limit=1G --no-progress -c '.escapeshellarg($this->generated->path('phpstan.neon')));

    expect($exit)->toBe(0, $output);
});

it('gives code with no placeholder, no task code and one strict_types declaration', function (): void {
    generateEverything($this->generated);

    foreach ($this->generated->files() as $file) {
        if (! str_ends_with($file, '.php') || str_starts_with($file, 'config/')) {
            continue;
        }
        $code = $this->generated->read($file);

        expect($code)->toStartWith("<?php\n\ndeclare(strict_types=1);\n")
            ->and(preg_match('/\{\{|\}\}/', $code))->toBe(0, $file)
            ->and(substr_count($code, 'declare(strict_types=1);'))->toBe(1, $file)
            ->and(str_contains($code, "\t"))->toBeFalse($file)
            ->and(preg_match('/\n{3,}/', $code))->toBe(0, $file.' has blank lines in a row')
            ->and(str_ends_with($code, "\n"))->toBeTrue($file);
    }
});
