<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * F52: OutputsStructured concern — `--json`/`--format` + meaningful non-zero exit
 * codes for guard:doctor and guard:catalog:validate.
 *
 * Acceptance criteria:
 *  - `--json` produces a valid, parseable payload;
 *  - a failure yields a non-zero exit code (both in text and JSON mode);
 *  - success yields exit code 0.
 *
 * These tests assert behaviour (exit code + decoded JSON shape), not internal
 * text, so CI pipelines can rely on the machine-readable contract.
 */
it('guard:doctor --json emits a valid parseable payload on success', function (): void {
    $code = Artisan::call('guard:doctor', ['--panel' => 'test', '--json' => true]);

    expect($code)->toBe(0);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toBeArray()
        ->toHaveKeys(['errors', 'warnings', 'abilities'])
        ->and($payload['errors'])->toBe([])
        // The projection carries the ability rows the text mode renders as a table.
        ->and($payload['abilities'])->toBeArray()->not->toBeEmpty();

    expect($payload['abilities'][0])
        ->toHaveKeys(['panel', 'ability', 'handler'])
        ->and($payload['abilities'][0]['panel'])->toBe('test');
});

it('guard:doctor --json returns a non-zero exit code when consistency errors exist', function (): void {
    $duplicatePath = __DIR__.'/../Stubs/Posts/Policies/DuplicatePostPolicy.php';

    File::put(path: $duplicatePath, contents: <<<'PHP'
<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Posts\Policies;

use AzGuard\Attributes\GateAbility;
use AzGuard\Tests\Stubs\Posts\Permissions\PostPermission;
use AzGuard\Tests\Stubs\User;

final class DuplicatePostPolicy
{
    #[GateAbility(permission: PostPermission::View)]
    public function canViewAgain(User $user): bool
    {
        return true;
    }
}
PHP);

    try {
        $code = Artisan::call('guard:doctor', ['--panel' => 'test', '--json' => true]);

        expect($code)->not->toBe(0);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($payload)->toBeArray()
            ->toHaveKeys(['errors', 'warnings', 'abilities'])
            ->and($payload['errors'])->toBeArray()->not->toBeEmpty();
    } finally {
        if (File::exists(path: $duplicatePath)) {
            File::delete(paths: $duplicatePath);
        }
    }
});

it('guard:doctor without --json still fails with a non-zero exit code on errors', function (): void {
    $duplicatePath = __DIR__.'/../Stubs/Posts/Policies/DuplicatePostPolicy.php';

    File::put(path: $duplicatePath, contents: <<<'PHP'
<?php

declare(strict_types=1);

namespace AzGuard\Tests\Stubs\Posts\Policies;

use AzGuard\Attributes\GateAbility;
use AzGuard\Tests\Stubs\Posts\Permissions\PostPermission;
use AzGuard\Tests\Stubs\User;

final class DuplicatePostPolicy
{
    #[GateAbility(permission: PostPermission::View)]
    public function canViewAgain(User $user): bool
    {
        return true;
    }
}
PHP);

    try {
        $this->artisan('guard:doctor', ['--panel' => 'test'])
            ->assertFailed();
    } finally {
        if (File::exists(path: $duplicatePath)) {
            File::delete(paths: $duplicatePath);
        }
    }
});

it('guard:catalog:validate --json emits a valid parseable payload', function (): void {
    $code = Artisan::call('guard:catalog:validate', ['--panel' => 'test', '--json' => true]);

    // The test panel has catalog keys without matching policies: warnings, not
    // errors — so non-strict mode is a success (exit 0).
    expect($code)->toBe(0);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toBeArray()
        ->toHaveKeys(['errors', 'warnings', 'abilities'])
        ->and($payload['errors'])->toBe([])
        ->and($payload['warnings'])->toBeArray()->not->toBeEmpty();
});

it('guard:catalog:validate --strict --json turns warnings into a non-zero exit code', function (): void {
    $code = Artisan::call(
        'guard:catalog:validate',
        ['--panel' => 'test', '--strict' => true, '--json' => true],
    );

    // --strict promotes the catalog warnings to a failure.
    expect($code)->not->toBe(0);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toBeArray()
        ->toHaveKeys(['errors', 'warnings', 'abilities'])
        // The failure is warning-driven; the human-facing warnings stay in the payload.
        ->and($payload['warnings'])->toBeArray()->not->toBeEmpty();
});

it('guard:catalog:validate --strict without --json also fails on warnings', function (): void {
    $this->artisan('guard:catalog:validate', ['--panel' => 'test', '--strict' => true])
        ->assertFailed();
});
