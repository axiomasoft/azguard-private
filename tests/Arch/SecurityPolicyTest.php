<?php

declare(strict_types=1);

/*
 * Disclosure policy gate (knowledge/security-gates#disclosure): the policy names the right private channel, the
 * repository identities in public text are the three AzGuard repositories, the retired organisation is gone, and the
 * promised response targets are present. No HTTP request: the live link check is the reporter drill.
 */

const SECURITY_REPOSITORIES = ['azguard-private', 'azguard', 'azguard-filament'];

function securityRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @return list<string> tracked files, relative to the root */
function securityTrackedFiles(): array
{
    $output = (string) shell_exec('git -C '.escapeshellarg(securityRoot()).' ls-files -z');

    return array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''
        && ! str_starts_with($path, 'audits/')
        && ! str_starts_with($path, 'plans/')
        && ! in_array($path, ['CHANGELOG.md', 'package-lock.json', 'composer.lock'], true)
        && is_file(securityRoot().'/'.$path)));
}

/** @return list<string> files whose repository links are checked */
function securityPublicTextFiles(): array
{
    return array_values(array_filter(securityTrackedFiles(), static fn (string $path): bool => in_array($path, ['SECURITY.md', 'README.md', 'CONTRIBUTING.md'], true)
        || (str_starts_with($path, 'docs/') && str_ends_with($path, '.md'))
        || (bool) preg_match('#^packages/[^/]+/composer\.json$#', $path)));
}

it('states the private reporting channel, the supported versions and the response targets', function (): void {
    $policy = (string) file_get_contents(securityRoot().'/SECURITY.md');

    expect($policy)
        ->toContain('https://github.com/axiomasoft/azguard-private/security/advisories/new')
        ->toContain('| `1.x` (latest minor) | yes |')
        ->toContain('| `main` | yes |')
        ->toContain('| `< 1.0` | no |')
        ->toContain('within 2 business days')
        ->toContain('within 3 business days')
        ->toContain('docs/advanced/security.md#emergency-release');
});

it('keeps the emergency release anchor and the security page in the docs', function (): void {
    $page = (string) file_get_contents(securityRoot().'/docs/advanced/security.md');

    expect($page)->toContain('## Emergency release')->toContain('## Limitations')
        ->and((string) file_get_contents(securityRoot().'/docs/.vitepress/config.ts'))->toContain("link: '/advanced/security'");
});

it('names only the AzGuard repositories of the axiomasoft organisation in public text', function (): void {
    $violations = [];

    foreach (securityPublicTextFiles() as $file) {
        preg_match_all('#github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)#', (string) file_get_contents(securityRoot().'/'.$file), $matches, PREG_SET_ORDER);

        foreach ($matches as [$url, $organisation, $repository]) {
            $repository = preg_replace('#\.git$#', '', $repository);
            $ours = str_starts_with($organisation, 'axioma') || str_starts_with($repository, 'azguard');

            if ($ours && ($organisation !== 'axiomasoft' || ! in_array($repository, SECURITY_REPOSITORIES, true))) {
                $violations[] = "{$file}: {$url}";
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps the retired organisation out of every tracked file', function (): void {
    $found = [];

    foreach (securityTrackedFiles() as $file) {
        if (str_contains((string) file_get_contents(securityRoot().'/'.$file), 'axioma-studio')) {
            $found[] = $file;
        }
    }

    expect($found)->toBe([]);
});
