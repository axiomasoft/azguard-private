<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/bin/api-compat.php';

/** @return array{root: string, git: Closure, old: string, candidate: string, spec: array<string, mixed>} */
function compatPolicyFixture(): array
{
    $root = sys_get_temp_dir().'/azguard-major-policy-'.bin2hex(random_bytes(6));
    mkdir($root);
    mkdir($root.'/docs/reference', 0777, true);
    $git = static function (string ...$arguments) use ($root): string {
        $output = compatGit($root, ['-c', 'user.name=fixture', '-c', 'user.email=fixture@example.invalid',
            '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', ...$arguments]);

        if ($output === null) {
            throw new RuntimeException('fixture git failed: '.implode(' ', $arguments));
        }

        return trim($output);
    };
    $git('init', '-q');
    $spec = ['schema' => COMPAT_SCHEMA, 'artifacts' => [['id' => 'example', 'kind' => 'format', 'path' => 'format.json']]];
    file_put_contents($root.'/compatibility.json', compatEncode($spec));
    file_put_contents($root.'/format.json', compatEncode(['value' => 'old']));
    $git('add', '.');
    $git('commit', '-qm', 'old contract');
    $old = $git('rev-parse', 'HEAD');
    $git('tag', 'v1.9.0');

    file_put_contents($root.'/format.json', compatEncode(['value' => 'two']));
    $git('commit', '-qam', 'initial v2 contract');
    $candidate = $git('rev-parse', 'HEAD');
    $spec += ['major' => 2, 'candidate' => $old, 'major_candidate' => $candidate,
        'rationale' => 'Preserve the v1-to-v2 value change; reject future v2 changes.',
        'migration_inventory' => 'docs/reference/compatibility-2.json'];
    file_put_contents($root.'/compatibility.json', compatEncode($spec));
    file_put_contents($root.'/'.$spec['migration_inventory'], compatEncode(compatMigrationInventory($root, $spec)));
    $git('add', '.');
    $git('commit', '-qm', 'v2 policy');

    return compact('root', 'git', 'old', 'candidate', 'spec');
}

beforeEach(function (): void {
    $this->policy = compatPolicyFixture();
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->policy['root']));
});

/** Run the real CLI logic in the fixture without loading baseline PHP. */
function compatPolicyExit(array $fixture, array $arguments = []): int
{
    // Keep expected red diagnostics out of the runner; only the temporary fixture is compared.
    $process = proc_open([PHP_BINARY, '-r',
        'require $argv[1]; exit(compatMain($argv[2], ["api-compat", ...array_slice($argv, 3)]));',
        dirname(__DIR__, 2).'/bin/api-compat.php', $fixture['root'], ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('cannot run fixture comparator');
    }
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process);
}

it('uses only stable tags in the declared major', function (): void {
    expect(compatSelectTag(['v1.99.0', 'v2.1.0', 'v2.10.0', 'v2.11.0-rc.1', 'v3.0.0'], [], 2))->toBe('v2.10.0');
    expect(compatBaseline($this->policy['root'], 'auto', $this->policy['spec'])['sha'])->toBe($this->policy['candidate']);
    expect(compatPolicyExit($this->policy))->toBe(0);
});

it('keeps old breaks visible for explicit cross-major comparisons', function (): void {
    $inventory = compatMigrationInventory($this->policy['root'], $this->policy['spec']);
    expect($inventory['baseline'])->toBe($this->policy['old'])
        ->and($inventory['breaks'])->toHaveCount(1)
        ->and($inventory['breaks'][0]['baseline'])->toBe('old')
        ->and($inventory['breaks'][0]['current'])->toBe('two')
        ->and(compatPolicyExit($this->policy, ['--against='.$this->policy['old']]))->toBe(1);
});

it('does not compare the first v2 tag with itself and blocks subsequent v2 breaks', function (): void {
    ['root' => $root, 'git' => $git, 'spec' => $spec, 'candidate' => $candidate] = $this->policy;
    $git('tag', 'v2.0.0');
    expect(compatBaseline($root, 'auto', $spec)['sha'])->toBe($candidate);
    $tagged = $git('rev-parse', 'HEAD');
    file_put_contents($root.'/format.json', compatEncode(['value' => 'broken']));
    $git('commit', '-qam', 'forbidden same-major break');
    expect(compatBaseline($root, 'auto', $spec))->toBe(['ref' => 'v2.0.0', 'sha' => $tagged])
        ->and(compatPolicyExit($this->policy))->toBe(1);
});

it('rejects missing or tampered migration inventory', function (): void {
    $path = $this->policy['root'].'/'.$this->policy['spec']['migration_inventory'];
    file_put_contents($path, compatEncode(['breaks' => []]));
    expect(compatPolicyExit($this->policy))->toBe(2);
    unlink($path);
    expect(compatPolicyExit($this->policy))->toBe(2)
        ->and(compatPolicyExit($this->policy, ['--write-migration-inventory']))->toBe(0)
        ->and(compatPolicyExit($this->policy))->toBe(0);
});

it('rejects a same-major break before the first stable v2 tag', function (): void {
    file_put_contents($this->policy['root'].'/format.json', compatEncode(['value' => 'broken']));
    expect(compatPolicyExit($this->policy))->toBe(1);
});

it('rejects a missing current artifact instead of silently omitting it', function (): void {
    unlink($this->policy['root'].'/format.json');
    expect(compatPolicyExit($this->policy))->toBe(2);
});

it('requires an explicit rationale and immutable distinct transition refs', function (): void {
    ['root' => $root, 'spec' => $spec] = $this->policy;
    $spec['rationale'] = '';
    expect(fn (): array => compatMigrationInventory($root, $spec))->toThrow(CompatError::class, 'explicit rationale');
    $spec['rationale'] = 'Intentional major transition';
    $spec['major_candidate'] = $spec['candidate'];
    expect(fn (): array => compatMigrationInventory($root, $spec))->toThrow(CompatError::class, 'distinct candidates');
    $spec['major_candidate'] = 'HEAD';
    expect(fn (): array => compatMigrationInventory($root, $spec))->toThrow(CompatError::class, 'immutable commit SHA');
});

it('fails closed without a candidate in the declared major', function (): void {
    ['root' => $root, 'spec' => $spec] = $this->policy;
    unset($spec['major_candidate']);
    expect(fn (): array => compatBaseline($root, 'auto', $spec))->toThrow(CompatError::class, 'no eligible stable tag');
    $spec['major'] = '2';
    expect(fn (): array => compatBaseline($root, 'auto', $spec))->toThrow(CompatError::class, 'positive integer');
    $spec['major'] = null;
    expect(fn (): array => compatBaseline($root, 'auto', $spec))->toThrow(CompatError::class, 'positive integer');
});

it('preserves the repository transition inventory exactly as generated from both pinned commits', function (): void {
    $root = dirname(__DIR__, 2);
    $spec = json_decode((string) file_get_contents($root.'/compatibility.json'), true, flags: JSON_THROW_ON_ERROR);
    $inventory = compatMigrationInventory($root, $spec);
    expect($inventory['breaks'])->not->toBeEmpty()
        ->and(file_get_contents(compatInventoryPath($root, $spec)))->toBe(compatEncode($inventory));
});

it('rejects HEAD and nonancestor fallback candidates', function (): void {
    ['root' => $root, 'git' => $git, 'spec' => $spec] = $this->policy;
    $spec['major_candidate'] = $git('rev-parse', 'HEAD');
    expect(fn (): array => compatBaseline($root, 'auto', $spec))->toThrow(CompatError::class, 'never HEAD itself');
    $git('checkout', '-qb', 'side');
    file_put_contents($root.'/side', 'side');
    $git('add', 'side');
    $git('commit', '-qm', 'side');
    $side = $git('rev-parse', 'HEAD');
    $git('checkout', '-q', '--detach', $spec['major_candidate']);
    $spec['major_candidate'] = $side;
    expect(fn (): array => compatBaseline($root, 'auto', $spec))->toThrow(CompatError::class, 'ancestor before HEAD');
});
