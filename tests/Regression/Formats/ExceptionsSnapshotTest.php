<?php

declare(strict_types=1);

use AzGuard\Exceptions\AzGuardException;
use AzGuard\Laravel\Http\DecisionFailedException;
use AzGuard\Tests\Fixtures\Formats\FormatSnapshot;

/*
 * I17, blocking now: every exception class of the package, its parent and its machine code.
 * Applications catch these classes and match these codes; a change is deliberate and updates the fixture.
 */

/** @return list<class-string<Throwable>> the exception classes of the package, sorted by short name */
function baselineExceptionClasses(): array
{
    $classes = [DecisionFailedException::class];

    foreach (glob(dirname(__DIR__, 3).'/packages/core/src/Exceptions/*.php') ?: [] as $file) {
        $classes[] = 'AzGuard\\Exceptions\\'.basename($file, '.php');
    }
    usort($classes, static fn (string $a, string $b): int => strcmp(class_basename($a), class_basename($b)));

    return $classes;
}

/** @return list<string> the literal checks of `InvalidConfigurationException::failing('…')` in the production code */
function baselineConfigurationChecks(): array
{
    $checks = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/packages', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php' || ! str_contains($file->getPathname(), '/src/')) {
            continue;
        }
        $tokens = array_values(array_filter(PhpToken::tokenize((string) file_get_contents($file->getPathname())),
            static fn (PhpToken $token): bool => ! $token->isIgnorable()));

        foreach ($tokens as $index => $token) {
            if ($token->text === 'failing' && ($tokens[$index - 2]->text ?? '') === 'InvalidConfigurationException'
                && ($tokens[$index + 1]->text ?? '') === '(' && ($tokens[$index + 2]->id ?? 0) === T_CONSTANT_ENCAPSED_STRING) {
                $checks[] = trim($tokens[$index + 2]->text, '\'"');
            }
        }
    }
    $checks = array_values(array_unique($checks));
    sort($checks, SORT_STRING);

    return $checks;
}

it('keeps the class, parent and code of every exception', function (): void {
    $rows = [];

    foreach (baselineExceptionClasses() as $class) {
        $reflection = new ReflectionClass($class);
        $code = $reflection->isAbstract() || ! $reflection->isSubclassOf(AzGuardException::class)
            ? null
            : $reflection->newInstanceWithoutConstructor()->code();
        $rows[class_basename($class)] = ['class' => $class, 'parent' => $reflection->getParentClass() === false ? null : $reflection->getParentClass()->getName(),
            'abstract' => $reflection->isAbstract(), 'final' => $reflection->isFinal(), 'code' => $code];
    }
    $snapshot = ['exceptions' => $rows, 'invalid_configuration_checks' => baselineConfigurationChecks()];
    [$actual, $stored] = FormatSnapshot::compare('exceptions', $snapshot);

    expect($actual)->toBe($stored);
});

it('gives every concrete AzGuard exception a snake_case code', function (): void {
    foreach (baselineExceptionClasses() as $class) {
        $reflection = new ReflectionClass($class);

        if (! $reflection->isAbstract() && $reflection->isSubclassOf(AzGuardException::class)) {
            expect($reflection->newInstanceWithoutConstructor()->code())->toMatch('/\A[a-z][a-z_]*[a-z]\z/');
        }
    }
});
