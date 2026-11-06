<?php

declare(strict_types=1);

/*
 * Disclosure policy gate (knowledge/security-gates#disclosure): the policy names the right private channel, the
 * repository identities in public text are the three AzGuard repositories, current links use the right owner, and the
 * best-effort response targets are present. No HTTP request: dated live verification is separate evidence.
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
        && ! in_array($path, ['CHANGELOG.md', 'package-lock.json', 'composer.lock', 'tests/Arch/SecurityPolicyTest.php'], true)
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
        ->toContain('Canonical private contact: [dv.vostrikov@gmail.com](mailto:dv.vostrikov@gmail.com)')
        ->toContain('https://github.com/axiomasoft/azguard/security/advisories/new')
        ->toContain('use email until that repository is public')
        ->toContain('Private reporting enablement could not be verified')
        ->not->toContain('https://github.com/axiomasoft/azguard-private/security/advisories/new')
        ->toContain('| `2.x` (latest stable minor) | Supported once published;')
        ->toContain('| `main` | Best-effort development fixes;')
        ->toContain('| `1.x` | Publication/support status unverified;')
        ->toContain('| `< 1.0` | Not supported |')
        ->toContain('within 3 business days')
        ->toContain('These are targets, not guarantees')
        ->toContain('no fixed LTS period')
        ->not->toContain('within 2 business days')
        ->toContain('docs/advanced/security.md#emergency-release');
});

it('gives both Composer packages the accessible public policy and the same private email contact', function (): void {
    foreach (['core', 'filament'] as $package) {
        $manifest = json_decode((string) file_get_contents(securityRoot()."/packages/{$package}/composer.json"), true, flags: JSON_THROW_ON_ERROR);

        expect($manifest['support']['security'] ?? null)
            ->toBe('https://github.com/axiomasoft/azguard-private/blob/main/SECURITY.md')
            ->and($manifest['support']['email'] ?? null)->toBe('dv.vostrikov@gmail.com')
            ->and($manifest['authors'][0]['email'])->toBe($manifest['support']['email']);
    }
});

it('documents coordinated disclosure without making CVE assignment or secrecy unconditional', function (): void {
    $policy = (string) file_get_contents(securityRoot().'/SECURITY.md');

    expect($policy)->toContain('GHSA')->toContain('CVE assignment is not guaranteed')
        ->toContain('There is no fixed embargo duration')
        ->toContain('exploitation, a leak or a delayed fix')
        ->toContain('patch for each affected supported stable line')
        ->toContain('next prerelease')
        ->toContain('A published tag is never moved or')
        ->not->toContain('nothing is published before a fixed release exists');
});

it('connects reporting docs to navigation, incident response and the upgrade and release policies', function (): void {
    $page = (string) file_get_contents(securityRoot().'/docs/security.md');
    $incident = (string) file_get_contents(securityRoot().'/docs/advanced/security.md');
    $release = (string) file_get_contents(securityRoot().'/RELEASING.md');

    expect($page)->toContain('mailto:dv.vostrikov@gmail.com')
        ->toContain('https://github.com/axiomasoft/azguard-private/blob/main/SECURITY.md')
        ->toContain('## Key compromise response')
        ->toContain('remove the')->toContain('compromised key from previous verification keys immediately')
        ->toContain('Do not copy or re-sign old entries or flush a shared Redis database')
        ->toContain('revoke/disable the compromised credential')
        ->and($incident)->toContain('../security.md')->not->toContain('within 2 business days')
        ->and($release)->toContain('Security channel and publication inventory 2026-11-03')
        ->not->toContain('triaged within 1 business day')
        ->and((string) file_get_contents(securityRoot().'/docs/guides/upgrading-2.md'))->toContain('[security support policy](../security.md)')
        ->and((string) file_get_contents(securityRoot().'/docs/.vitepress/config.ts'))->toContain("link: '/security'");
});

it('prepares an expiring security.txt while documenting that hosting is still unverified', function (): void {
    $asset = (string) file_get_contents(securityRoot().'/docs/public/.well-known/security.txt');
    $page = (string) file_get_contents(securityRoot().'/docs/security.md');
    preg_match('/^Expires: (.+)$/m', $asset, $matches);
    $expires = new DateTimeImmutable($matches[1]);

    expect($asset)->toContain('Contact: mailto:dv.vostrikov@gmail.com')
        ->toContain('Canonical: https://axiomasoft.github.io/azguard-private/.well-known/security.txt')
        ->toContain('Policy: https://github.com/axiomasoft/azguard-private/blob/main/SECURITY.md')
        ->and($expires > new DateTimeImmutable('now', new DateTimeZone('UTC')))->toBeTrue()
        ->and($expires <= new DateTimeImmutable('+1 year', new DateTimeZone('UTC')))->toBeTrue()
        ->and($page)->toContain('does **not** establish a working hosted endpoint')
        ->toContain('Origin-root publication requires');
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

function securityHasRetiredOwnerLink(string $text): bool
{
    return preg_match('#(?:github\.com/|packagist\.org/packages/|composer require\s+)axioma-studio/#', $text) === 1;
}

it('keeps the retired organisation out of current links without erasing historical artifact identities', function (): void {
    $found = [];

    foreach (securityTrackedFiles() as $file) {
        if (securityHasRetiredOwnerLink((string) file_get_contents(securityRoot().'/'.$file))) {
            $found[] = $file;
        }
    }

    expect($found)->toBe([]);
});

it('rejects retired owner links but allows a quoted historical package identity', function (): void {
    expect(securityHasRetiredOwnerLink('https://github.com/axioma-studio/azguard/security/advisories/new'))->toBeTrue()
        ->and(securityHasRetiredOwnerLink('https://packagist.org/packages/axioma-studio/azguard'))->toBeTrue()
        ->and(securityHasRetiredOwnerLink('composer require axioma-studio/azguard'))->toBeTrue()
        ->and(securityHasRetiredOwnerLink('https://github.com/axiomasoft/azguard'))->toBeFalse()
        ->and(securityHasRetiredOwnerLink('At v0.3.0, the package was `axioma-studio/azguard-core`.'))->toBeFalse();
});
