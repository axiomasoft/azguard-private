<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 2).'/bin/release-evidence.php';

/*
 * The release evidence validator (knowledge/release-integrity#evidence): the valid fixture passes offline and against
 * recorded runs, and each negative fixture breaks exactly the rule it is named after, so a validator that stops detecting
 * a rule fails here. A swapped run commit cannot be seen offline, only against the recorded runs.
 */

function evidenceFixture(string $name): array
{
    return Yaml::parseFile(__DIR__.'/Fixtures/ReleaseEvidence/'.$name, Yaml::PARSE_DATETIME);
}

/** @return list<string> */
function evidenceLive(array $evidence, string $runs): array
{
    $dir = __DIR__.'/Fixtures/ReleaseEvidence/'.$runs;

    return evidenceLiveViolations(
        $evidence,
        static fn (string $id): ?array => json_decode((string) @file_get_contents("{$dir}/{$id}.json"), true) ?: null,
        static fn (): ?string => json_decode((string) file_get_contents("{$dir}/tag.json"), true)['sha'] ?? null,
        static fn (string $digest): bool => in_array($digest, json_decode((string) file_get_contents("{$dir}/attestations.json"), true), true),
    );
}

/** @param list<string> $violations @return list<string> */
function evidenceRules(array $violations): array
{
    return array_values(array_unique(array_map(static fn (string $line): string => explode(' ', $line)[0], $violations)));
}

it('accepts the valid fixture offline and against the recorded runs', function (): void {
    $valid = evidenceFixture('valid.yml');

    expect(evidenceViolations($valid))->toBe([])
        ->and(evidenceLive($valid, 'runs-ok'))->toBe([])
        ->and(evidenceApprovalViolations($valid))->toBe([]);
});

it('detects the rule each negative fixture is named after', function (string $fixture, string $rule): void {
    expect(evidenceRules(evidenceViolations(evidenceFixture($fixture))))->toBe([$rule]);
})->with([
    'malformed sha' => ['malformed-sha.yml', 'E3'],
    'missing workflow' => ['missing-workflow.yml', 'E5'],
    'failed local run' => ['failed-local.yml', 'E6'],
]);

it('rejects a workflow run on another commit only against the runs', function (): void {
    $valid = evidenceFixture('valid.yml');

    expect(evidenceViolations($valid))->toBe([])
        ->and(evidenceRules(evidenceLive($valid, 'runs-swapped-sha')))->toBe(['L1']);
});

it('rejects a tag that points elsewhere, a failed run, a push of another tag and an unattested artifact', function (): void {
    $valid = evidenceFixture('valid.yml');
    $run = static fn (array $change): callable => static fn (string $id): array => array_merge(['head_sha' => $valid['commit'], 'status' => 'completed', 'conclusion' => 'success', 'event' => 'push', 'head_branch' => 'v'.$valid['version']], $change);
    $tag = static fn (): string => $valid['commit'];
    $attested = static fn (string $digest): bool => true;

    expect(evidenceRules(evidenceLiveViolations($valid, $run([]), static fn (): string => str_repeat('f', 40), $attested)))->toBe(['L3'])
        ->and(evidenceRules(evidenceLiveViolations($valid, $run(['conclusion' => 'failure']), $tag, $attested)))->toBe(['L2'])
        ->and(evidenceRules(evidenceLiveViolations($valid, $run(['head_branch' => 'main']), $tag, $attested)))->toBe(['L4'])
        ->and(evidenceRules(evidenceLiveViolations($valid, $run([]), $tag, static fn (string $digest): bool => false)))->toBe(['L5']);
});

it('requires the owner approval only when asked and refuses non-SemVer versions and a wrong schema', function (): void {
    $valid = evidenceFixture('valid.yml');
    unset($valid['owner_approval']);

    expect(evidenceViolations($valid))->toBe([])
        ->and(evidenceRules(evidenceApprovalViolations($valid)))->toBe(['A1'])
        ->and(evidenceRules(evidenceViolations([...evidenceFixture('valid.yml'), 'version' => '1.0'])))->toBe(['E2'])
        ->and(evidenceRules(evidenceViolations([...evidenceFixture('valid.yml'), 'schema' => 'x'])))->toBe(['E1']);
});
