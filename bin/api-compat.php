<?php

declare(strict_types=1);

/*
 * Compatibility comparison of the supported surface with a baseline (I18, D26).
 *
 *   php bin/api-compat.php [--against=<ref>|auto] [--json]
 *
 * The artifacts are listed in the root `compatibility.json`: the attribute manifests (`kind: api`) and the format
 * fixtures (`kind: format`). The baseline is a git ref; `auto` takes the newest stable tag vX.Y.Z (X >= 1, no
 * pre-release) merged into HEAD, else the `candidate` sha of `compatibility.json`. Baseline files are read with
 * `git show` and parsed as JSON only: no baseline code is loaded or executed. `composer api:check` runs first, since a
 * stale manifest would hide a break.
 *
 * Exit 0: compatible (additions are listed); 1: breaks, one line each `<rule-id> <artifact-id> <locator>: <baseline> ->
 * <current>`; 2: usage or baseline error.
 *
 * The functions are loadable without running the CLI (tests/Arch/CompatibilityTest.php requires this file).
 */

const COMPAT_SCHEMA = 'urn:azguard:compatibility:1';

/** The stable tag form `auto` accepts: major >= 1 and no pre-release suffix. */
const COMPAT_STABLE_TAG = '/^v[1-9][0-9]*\.[0-9]+\.[0-9]+$/';

/** Columns that identify the objects of a list in a format artifact, in order of preference. */
const COMPAT_LIST_KEYS = ['name', 'fqcn', 'type', 'case', 'key'];

final class CompatError extends RuntimeException {}

/**
 * The newest stable tag of a list of tag names, or null; a tag in $excluded never qualifies.
 *
 * @param  list<string>  $tags
 * @param  list<string>  $excluded  tags that must not be the baseline (those of the compared commit itself)
 */
function compatSelectTag(array $tags, array $excluded = []): ?string
{
    $stable = array_values(array_filter($tags, static fn (string $tag): bool => preg_match(COMPAT_STABLE_TAG, $tag) === 1 && ! in_array($tag, $excluded, true)));
    usort($stable, static fn (string $a, string $b): int => version_compare(substr($b, 1), substr($a, 1)));

    return $stable[0] ?? null;
}

/**
 * Runs git in the repository and returns its standard output, or null when git fails.
 *
 * @param  list<string>  $arguments
 */
function compatGit(string $root, array $arguments): ?string
{
    $process = proc_open(['git', '-C', $root, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        throw new CompatError('cannot run git');
    }
    $output = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0 ? $output : null;
}

/**
 * The baseline a comparison runs against.
 *
 * @param  array<string, mixed>  $spec  the current compatibility.json
 * @return array{ref: string, sha: string}
 */
function compatBaseline(string $root, string $against, array $spec): array
{
    if ($against === 'auto') {
        $tags = compatGit($root, ['tag', '--merged', 'HEAD']);

        if ($tags === null) {
            throw new CompatError('cannot list the tags merged into HEAD');
        }
        // A tag on HEAD itself would compare the tree with itself (the release run on a tag): it is not a baseline.
        $own = compatGit($root, ['tag', '--points-at', 'HEAD']);

        if ($own === null) {
            throw new CompatError('cannot list the tags of HEAD');
        }
        $tag = compatSelectTag(
            preg_split('/\R/', trim($tags), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            preg_split('/\R/', trim($own), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        );
        $against = $tag ?? $spec['candidate'] ?? null;

        if (! is_string($against) || $against === '') {
            throw new CompatError('no compatibility baseline: no stable tag v1+ is merged into HEAD and compatibility.json has no candidate');
        }
    }
    $sha = compatGit($root, ['rev-parse', '--verify', '--quiet', $against.'^{commit}']);

    if ($sha === null) {
        throw new CompatError("unknown baseline ref {$against}");
    }

    return ['ref' => $against, 'sha' => trim($sha)];
}

/**
 * The validated artifact list of a compatibility.json.
 *
 * @return array<string, array{path: string, kind: string}> by artifact id
 */
function compatArtifactList(mixed $spec, string $source): array
{
    if (! is_array($spec) || ($spec['schema'] ?? null) !== COMPAT_SCHEMA || ! is_array($spec['artifacts'] ?? null)) {
        throw new CompatError("{$source}: not a ".COMPAT_SCHEMA.' document');
    }
    $artifacts = [];

    foreach ($spec['artifacts'] as $artifact) {
        $id = $artifact['id'] ?? null;
        $path = $artifact['path'] ?? null;
        $kind = $artifact['kind'] ?? null;

        if (! is_string($id) || ! is_string($path) || ! in_array($kind, ['api', 'format'], true) || isset($artifacts[$id])) {
            throw new CompatError("{$source}: malformed or duplicate artifact ".json_encode($artifact));
        }
        $artifacts[$id] = ['path' => $path, 'kind' => $kind];
    }

    return $artifacts;
}

/**
 * Reads the artifacts of a list. `$read` returns the file content or null when the file does not exist; a missing
 * file is an error when `$required`, else the artifact is left out (it did not exist at the baseline).
 *
 * @param  array<string, array{path: string, kind: string}>  $list
 * @param  callable(string): ?string  $read
 * @return array<string, array{kind: string, path: string, data: array<mixed>}>
 */
function compatCollect(array $list, callable $read, bool $required, string $source): array
{
    $artifacts = [];

    foreach ($list as $id => $artifact) {
        $content = $read($artifact['path']);

        if ($content === null) {
            if ($required) {
                throw new CompatError("{$source}: artifact {$id} ({$artifact['path']}) is missing");
            }

            continue;
        }
        $data = json_decode($content, true);

        if (! is_array($data)) {
            throw new CompatError("{$source}: artifact {$id} ({$artifact['path']}) is not JSON");
        }
        $artifacts[$id] = [...$artifact, 'data' => $data];
    }

    return $artifacts;
}

/**
 * Compares every artifact of the baseline with the current one. API artifacts are compared as one surface, so a type
 * that moves between packages is not removed; format artifacts are compared each on its own.
 *
 * @param  array<string, array{kind: string, data: array<mixed>}>  $baseline
 * @param  array<string, array{kind: string, data: array<mixed>}>  $current
 * @return array{breaks: list<array{rule: string, artifact: string, locator: string, baseline: mixed, current: mixed}>, additions: list<array{rule: string, artifact: string, locator: string, current: mixed}>}
 */
function compatCompare(array $baseline, array $current): array
{
    $result = ['breaks' => [], 'additions' => []];

    foreach ($baseline as $id => $artifact) {
        if (! isset($current[$id])) {
            $result['breaks'][] = compatBreak('artifact.removed', $id, $id, $artifact['kind'], null);
        }
    }
    foreach (array_diff_key($current, $baseline) as $id => $artifact) {
        $result['additions'][] = compatAddition('artifact.added', $id, $id, $artifact['kind']);
    }

    $pick = static fn (array $artifacts, string $kind): array => array_map(
        static fn (array $artifact): array => $artifact['data'],
        array_filter($artifacts, static fn (array $artifact): bool => $artifact['kind'] === $kind),
    );
    $api = compatApi($pick($baseline, 'api'), $pick($current, 'api'));

    $baseFormats = $pick($baseline, 'format');
    $currentFormats = $pick($current, 'format');
    $context = ['new_migrations' => array_values(array_diff(
        compatMigrationBasenames($currentFormats['resources'] ?? []),
        compatMigrationBasenames($baseFormats['resources'] ?? []),
    ))];
    $formats = ['breaks' => [], 'additions' => []];

    foreach ($baseFormats as $id => $data) {
        if (isset($currentFormats[$id])) {
            $compared = compatFormat($id, $data, $currentFormats[$id], $context);
            array_push($formats['breaks'], ...$compared['breaks']);
            array_push($formats['additions'], ...$compared['additions']);
        }
    }

    return compatSorted([
        'breaks' => [...$result['breaks'], ...$api['breaks'], ...$formats['breaks']],
        'additions' => [...$result['additions'], ...$api['additions'], ...$formats['additions']],
    ]);
}

/**
 * @param  array{breaks: list<array<string, mixed>>, additions: list<array<string, mixed>>}  $result
 * @return array{breaks: list<array<string, mixed>>, additions: list<array<string, mixed>>}
 */
function compatSorted(array $result): array
{
    foreach (['breaks', 'additions'] as $key) {
        usort($result[$key], static fn (array $a, array $b): int => [$a['artifact'], $a['locator'], $a['rule']] <=> [$b['artifact'], $b['locator'], $b['rule']]);
    }

    return $result;
}

/** @return array{rule: string, artifact: string, locator: string, baseline: mixed, current: mixed} */
function compatBreak(string $rule, string $artifact, string $locator, mixed $baseline, mixed $current): array
{
    return ['rule' => $rule, 'artifact' => $artifact, 'locator' => $locator, 'baseline' => $baseline, 'current' => $current];
}

/** @return array{rule: string, artifact: string, locator: string, current: mixed} */
function compatAddition(string $rule, string $artifact, string $locator, mixed $current): array
{
    return ['rule' => $rule, 'artifact' => $artifact, 'locator' => $locator, 'current' => $current];
}

/* ---------------------------------------------------------------- PHP surface (attribute manifests) */

/**
 * The PHP rules of p4-execution#compatibility over the attribute manifests, by artifact id.
 *
 * @param  array<string, array<mixed>>  $base
 * @param  array<string, array<mixed>>  $current
 * @return array{breaks: list<array<string, mixed>>, additions: list<array<string, mixed>>}
 */
function compatApi(array $base, array $current): array
{
    $breaks = [];
    $additions = [];
    $baseTypes = compatTypes($base);
    $currentTypes = compatTypes($current);

    foreach ($baseTypes as $name => [$artifact, $type]) {
        $now = $currentTypes[$name][1] ?? null;

        if ($now === null) {
            $breaks[] = compatBreak('type.removed', $artifact, $name, $type['publication'], null);

            continue;
        }
        array_push($breaks, ...compatTypeBreaks($artifact, $type, $now, $currentTypes));
        array_push($additions, ...compatTypeAdditions($artifact, $type, $now));
    }

    foreach (array_diff_key($currentTypes, $baseTypes) as $name => [$artifact, $type]) {
        $additions[] = compatAddition('type.added', $artifact, $name, $type['publication']);
    }

    $baseRoots = compatRoots($base);
    $currentRoots = compatRoots($current);
    foreach ($baseRoots as $prefix => [$artifact, $directory]) {
        if (! isset($currentRoots[$prefix])) {
            $breaks[] = compatBreak('roots.changed', $artifact, 'roots/'.$prefix, $directory, null);
        }
    }

    foreach ($base as $artifact => $manifest) {
        if (! isset($manifest['facade'])) {
            continue;
        }
        $facade = compatByName($current[$artifact]['facade'] ?? []);

        foreach (compatByName($manifest['facade']) as $method => $described) {
            if (! isset($facade[$method])) {
                $breaks[] = compatBreak('facade.removed', $artifact, 'facade/'.$method, compatSignature($described), null);
            } elseif (compatMethodBreaks($artifact, 'facade/'.$method, $described, $facade[$method], overridable: false, renamable: false) !== []) {
                $breaks[] = compatBreak('facade.changed', $artifact, 'facade/'.$method, compatSignature($described), compatSignature($facade[$method]));
            }
        }
    }

    return ['breaks' => $breaks, 'additions' => $additions];
}

/**
 * Every supported type of a set of manifests with the artifact that lists it.
 *
 * @param  array<string, array<mixed>>  $manifests
 * @return array<string, array{0: string, 1: array<string, mixed>}>
 */
function compatTypes(array $manifests): array
{
    $types = [];

    foreach ($manifests as $artifact => $manifest) {
        foreach ($manifest['classes'] ?? [] as $type) {
            $types[$type['name']] = [$artifact, $type];
        }
    }

    return $types;
}

/**
 * @param  array<string, array<mixed>>  $manifests
 * @return array<string, array{0: string, 1: string}>
 */
function compatRoots(array $manifests): array
{
    $roots = [];

    foreach ($manifests as $artifact => $manifest) {
        foreach ($manifest['roots'] ?? [] as $prefix => $directory) {
            $roots[$prefix] = [$artifact, $directory];
        }
    }

    return $roots;
}

/**
 * @param  list<array<string, mixed>>  $entries
 * @return array<string, array<string, mixed>>
 */
function compatByName(array $entries): array
{
    $named = [];

    foreach ($entries as $entry) {
        $named[$entry['name']] = $entry;
    }

    return $named;
}

/**
 * @param  array<string, mixed>  $type
 * @param  array<string, mixed>  $now
 * @param  array<string, array{0: string, 1: array<string, mixed>}>  $currentTypes
 * @return list<array<string, mixed>>
 */
function compatTypeBreaks(string $artifact, array $type, array $now, array $currentTypes): array
{
    $name = $type['name'];
    $breaks = [];

    foreach (['publication', 'since'] as $field) {
        if ($type[$field] !== $now[$field]) {
            $breaks[] = compatBreak('type.publication_changed', $artifact, $name.'#'.$field, $type[$field], $now[$field]);
        }
    }

    if ($type['kind'] !== $now['kind']) {
        // Another kind of type: its members are not comparable.
        return [...$breaks, compatBreak('type.shape_changed', $artifact, $name.'#kind', $type['kind'], $now['kind'])];
    }
    foreach (['final', 'readonly', 'abstract'] as $flag) {
        if (! $type[$flag] && $now[$flag]) {
            $breaks[] = compatBreak('type.shape_changed', $artifact, $name.'#'.$flag, false, true);
        }
    }

    if ($type['parent'] !== null && $type['parent'] !== $now['parent']) {
        $breaks[] = compatBreak('type.shape_changed', $artifact, $name.'#parent', $type['parent'], $now['parent']);
    }
    foreach (array_diff($type['interfaces'], $now['interfaces']) as $interface) {
        $breaks[] = compatBreak('type.shape_changed', $artifact, $name.'#interfaces/'.$interface, $interface, null);
    }

    if ($type['publication'] === 'spi-implement' && $type['kind'] === 'interface') {
        // A new parent interface makes every implementer implement its methods.
        foreach (array_diff($now['interfaces'], $type['interfaces']) as $interface) {
            $breaks[] = compatBreak('implement.method_added', $artifact, $name.'#interfaces/'.$interface, null, $interface);
        }
    }

    if ($type['namedArguments'] && ! $now['namedArguments']) {
        $breaks[] = compatBreak('named.stance_changed', $artifact, $name.'#namedArguments', true, false);
    }

    if ($type['contracts'] !== $now['contracts']) {
        $breaks[] = compatBreak('contracts.changed', $artifact, $name.'#contracts', $type['contracts'], $now['contracts']);
    }

    if ($type['kind'] === 'enum') {
        array_push($breaks, ...compatEnumBreaks($artifact, $type, $now));
    }

    foreach (compatByName($type['constants']) as $constant => $described) {
        $found = compatInherited($currentTypes, $name, 'constants', $constant);
        $locator = $name.'::'.$constant;

        if ($found === null) {
            $breaks[] = compatBreak('member.removed', $artifact, $locator, $described['value'], null);

            continue;
        }

        if ($described['visibility'] === 'public' && $found['visibility'] !== 'public' || ! $described['final'] && $found['final']) {
            $breaks[] = compatBreak('member.visibility_narrowed', $artifact, $locator, compatModifiers($described), compatModifiers($found));
        }

        if ($described['value'] !== $found['value']) {
            $breaks[] = compatBreak('constant.value_changed', $artifact, $locator, $described['value'], $found['value']);
        }
    }

    foreach (compatByName($type['properties']) as $property => $described) {
        $found = compatInherited($currentTypes, $name, 'properties', $property);
        $locator = $name.'::$'.$property;

        if ($found === null) {
            $breaks[] = compatBreak('member.removed', $artifact, $locator, $described['type'], null);

            continue;
        }

        if ($described['visibility'] === 'public' && $found['visibility'] !== 'public'
            || $described['static'] !== $found['static'] || ! $described['readonly'] && $found['readonly']) {
            $breaks[] = compatBreak('member.visibility_narrowed', $artifact, $locator, compatModifiers($described), compatModifiers($found));
        }

        // What reading the property returns.
        if ($described['type'] !== $found['type']) {
            $breaks[] = compatBreak('member.return_changed', $artifact, $locator, $described['type'], $found['type']);
        }

        if ($described['contracts'] !== $found['contracts']) {
            $breaks[] = compatBreak('contracts.changed', $artifact, $locator, $described['contracts'], $found['contracts']);
        }
    }

    $methods = compatByName($type['methods']);
    foreach ($methods as $method => $described) {
        $found = compatInherited($currentTypes, $name, 'methods', $method);
        $locator = $name.'::'.$method.'()';

        if ($found === null) {
            $breaks[] = compatBreak('member.removed', $artifact, $locator, compatSignature($described), null);

            continue;
        }
        array_push($breaks, ...compatMethodBreaks(
            $artifact,
            $locator,
            $described,
            $found,
            overridable: compatOverridable($type, $described),
            renamable: $type['publication'] === 'spi-implement' && $type['kind'] === 'interface',
        ));
    }

    foreach (array_diff_key(compatByName($now['methods']), $methods) as $method => $described) {
        $locator = $name.'::'.$method.'()';

        if ($type['publication'] === 'spi-implement' && $type['kind'] === 'interface') {
            $breaks[] = compatBreak('implement.method_added', $artifact, $locator, null, compatSignature($described));
        } elseif ($described['abstract'] && ! $now['final']) {
            $breaks[] = compatBreak('implement.method_added', $artifact, $locator, null, compatSignature($described));
        }
    }

    return $breaks;
}

/**
 * Whether implementers may override a method: every method of an `spi-implement` type; on a class or trait, a method
 * that is not final of a type that is not final. Interfaces that are not `spi-implement` have no implementers outside
 * the package. A constructor is overridable only when it is abstract (PHP does not check constructor signatures).
 *
 * @param  array<string, mixed>  $type
 * @param  array<string, mixed>  $method
 */
function compatOverridable(array $type, array $method): bool
{
    if ($method['name'] === '__construct' && ! $method['abstract'] && $type['kind'] !== 'interface') {
        return false;
    }

    if ($type['publication'] === 'spi-implement') {
        return true;
    }

    return $type['kind'] !== 'interface' && ! $type['final'] && ! $method['final'];
}

/**
 * A member of a type or of one of its current supported ancestors: a member moved up the hierarchy is not removed.
 *
 * @param  array<string, array{0: string, 1: array<string, mixed>}>  $types
 * @return array<string, mixed>|null
 */
function compatInherited(array $types, string $name, string $category, string $member, int $depth = 0): ?array
{
    $type = $types[$name][1] ?? null;

    if ($type === null || $depth > 32) {
        return null;
    }
    foreach ($type[$category] as $entry) {
        if ($entry['name'] === $member) {
            return $entry;
        }
    }
    foreach ([$type['parent'], ...$type['interfaces']] as $ancestor) {
        if (is_string($ancestor) && ($found = compatInherited($types, $ancestor, $category, $member, $depth + 1)) !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * @param  array<string, mixed>  $member
 * @return list<string>
 */
function compatModifiers(array $member): array
{
    $modifiers = [$member['visibility']];

    foreach (['static', 'final', 'readonly', 'abstract'] as $flag) {
        if ($member[$flag] ?? false) {
            $modifiers[] = $flag;
        }
    }

    return $modifiers;
}

/** @param array<string, mixed> $method */
function compatSignature(array $method): string
{
    $parameters = array_map(static function (array $parameter): string {
        $text = trim(($parameter['type'] ?? '').' '.($parameter['byRef'] ? '&' : '').($parameter['variadic'] ? '...' : '').'$'.$parameter['name']);

        return $parameter['hasDefault'] ? $text.' = '.$parameter['default'] : $text;
    }, $method['parameters']);

    return implode(' ', compatModifiers($method)).' '.$method['name'].'('.implode(', ', $parameters).')'.($method['return'] !== null ? ': '.$method['return'] : '');
}

/**
 * The breaks between two descriptions of one method. Parameters are matched by position: the same position under
 * another name is a rename, a name found at another position is a reorder.
 *
 * @param  array<string, mixed>  $method
 * @param  array<string, mixed>  $now
 * @return list<array<string, mixed>>
 */
function compatMethodBreaks(string $artifact, string $locator, array $method, array $now, bool $overridable, bool $renamable): array
{
    $breaks = [];

    if ($method['visibility'] === 'public' && $now['visibility'] !== 'public' || $method['static'] !== $now['static'] || ! $method['final'] && $now['final']) {
        $breaks[] = compatBreak('member.visibility_narrowed', $artifact, $locator, compatModifiers($method), compatModifiers($now));
    }

    if (! $method['abstract'] && $now['abstract']) {
        $breaks[] = compatBreak('implement.method_added', $artifact, $locator, compatSignature($method), compatSignature($now));
    }

    if ($method['return'] !== $now['return']) {
        $breaks[] = compatBreak('member.return_changed', $artifact, $locator, $method['return'], $now['return']);
    }

    if ($method['namedArguments'] && ! $now['namedArguments']) {
        $breaks[] = compatBreak('named.stance_changed', $artifact, $locator, true, false);
    }

    if ($method['contracts'] !== $now['contracts']) {
        $breaks[] = compatBreak('contracts.changed', $artifact, $locator, $method['contracts'], $now['contracts']);
    }

    $before = $method['parameters'];
    $after = $now['parameters'];
    $names = array_column($after, 'name');

    foreach ($before as $position => $parameter) {
        $at = $locator.'$'.$parameter['name'];
        $moved = array_search($parameter['name'], $names, true);

        if (! isset($after[$position])) {
            $breaks[] = $moved === false
                ? compatBreak('parameter.removed', $artifact, $at, $parameter['name'], null)
                : compatBreak('parameter.reordered', $artifact, $at, $position, $moved);

            continue;
        }
        $parameterNow = $after[$position];

        if ($parameterNow['name'] !== $parameter['name']) {
            if ($moved !== false) {
                $breaks[] = compatBreak('parameter.reordered', $artifact, $at, $position, $moved);

                continue;
            }

            if ($method['namedArguments'] && ! $renamable) {
                $breaks[] = compatBreak('parameter.renamed', $artifact, $at, $parameter['name'], $parameterNow['name']);
            }
        }

        if ($parameter['type'] !== $parameterNow['type']) {
            $breaks[] = compatBreak('parameter.type_changed', $artifact, $at, $parameter['type'], $parameterNow['type']);
        }
        $default = static fn (array $p): array => [$p['hasDefault'] ? $p['default'] : null, $p['byRef'], $p['variadic']];

        if ($parameter['hasDefault'] && (! $parameterNow['hasDefault'] || $parameter['default'] !== $parameterNow['default'])
            || $parameter['byRef'] !== $parameterNow['byRef'] || $parameter['variadic'] !== $parameterNow['variadic']) {
            $breaks[] = compatBreak('parameter.default_changed', $artifact, $at, $default($parameter), $default($parameterNow));
        }
    }

    foreach (array_slice($after, count($before), preserve_keys: true) as $position => $parameter) {
        $at = $locator.'$'.$parameter['name'];

        if (in_array($parameter['name'], array_column($before, 'name'), true)) {
            continue;
        }

        if (! $parameter['hasDefault'] && ! $parameter['variadic']) {
            $breaks[] = compatBreak('parameter.added_required', $artifact, $at, null, $position);
        } elseif ($overridable) {
            $breaks[] = compatBreak('parameter.added_overridable', $artifact, $at, null, $position);
        }
    }

    return $breaks;
}

/**
 * @param  array<string, mixed>  $type
 * @param  array<string, mixed>  $now
 * @return list<array<string, mixed>>
 */
function compatEnumBreaks(string $artifact, array $type, array $now): array
{
    $name = $type['name'];
    $breaks = [];
    $cases = compatByName($now['cases']);

    foreach ($type['cases'] as $case) {
        $locator = $name.'::'.$case['name'];

        if (! isset($cases[$case['name']])) {
            $breaks[] = compatBreak('enum.case_removed', $artifact, $locator, $case['value'], null);
        } elseif ($cases[$case['name']]['value'] !== $case['value']) {
            $breaks[] = compatBreak('enum.value_changed', $artifact, $locator, $case['value'], $cases[$case['name']]['value']);
        }
    }

    if ($type['enum'] === 'closed') {
        foreach (array_diff_key($cases, compatByName($type['cases'])) as $case => $described) {
            $breaks[] = compatBreak('enum.closed_case_added', $artifact, $name.'::'.$case, null, $described['value']);
        }

        if ($now['enum'] === 'open') {
            $breaks[] = compatBreak('enum.closed_reopened', $artifact, $name.'#enum', 'closed', 'open');
        }
    }

    return $breaks;
}

/**
 * @param  array<string, mixed>  $type
 * @param  array<string, mixed>  $now
 * @return list<array<string, mixed>>
 */
function compatTypeAdditions(string $artifact, array $type, array $now): array
{
    $name = $type['name'];
    $additions = [];

    if ($type['kind'] !== $now['kind']) {
        return [];
    }

    if (! $type['namedArguments'] && $now['namedArguments']) {
        $additions[] = compatAddition('named.stance_opened', $artifact, $name.'#namedArguments', true);
    }

    if (($type['enum'] ?? null) === 'open' && ($now['enum'] ?? null) === 'closed') {
        $additions[] = compatAddition('enum.closed', $artifact, $name.'#enum', 'closed');
    }

    if (($type['enum'] ?? null) === 'open') {
        foreach (array_diff_key(compatByName($now['cases']), compatByName($type['cases'])) as $case => $described) {
            $additions[] = compatAddition('enum.open_case_added', $artifact, $name.'::'.$case, $described['value']);
        }
    }
    foreach (['constants' => '::', 'properties' => '::$'] as $category => $separator) {
        foreach (array_diff_key(compatByName($now[$category]), compatByName($type[$category])) as $member => $_) {
            $additions[] = compatAddition('member.added', $artifact, $name.$separator.$member, $category);
        }
    }
    $methods = compatByName($type['methods']);
    foreach (compatByName($now['methods']) as $method => $described) {
        $locator = $name.'::'.$method.'()';

        if (! isset($methods[$method])) {
            $additions[] = compatAddition('member.added', $artifact, $locator, compatSignature($described));

            continue;
        }

        if ($methods[$method]['namedArguments'] === false && $described['namedArguments']) {
            $additions[] = compatAddition('named.stance_opened', $artifact, $locator, true);
        }
        $before = array_column($methods[$method]['parameters'], 'name');
        foreach (array_slice($described['parameters'], count($methods[$method]['parameters'])) as $parameter) {
            if (! in_array($parameter['name'], $before, true) && ($parameter['hasDefault'] || $parameter['variadic'])
                && ! compatOverridable($type, $methods[$method])) {
                $additions[] = compatAddition('parameter.added_optional', $artifact, $locator.'$'.$parameter['name'], $parameter['default'] ?? '...');
            }
        }
    }

    return $additions;
}

/* ---------------------------------------------------------------- Formats (fixtures) */

/**
 * The format rules of p4-execution#compatibility: every baseline entry exists in the current artifact with an equal
 * value; entries may be added. `$context['new_migrations']` lists the migration basenames the current `resources` adds,
 * which the DDL artifacts need for a new table, column, index or constraint.
 *
 * @param  array<mixed>  $base
 * @param  array<mixed>  $current
 * @param  array{new_migrations?: list<string>}  $context
 * @return array{breaks: list<array<string, mixed>>, additions: list<array<string, mixed>>}
 */
function compatFormat(string $id, array $base, array $current, array $context = []): array
{
    $exactLists = [];

    if ($id === 'doctor-checks') {
        // The internal check FQCN is only the JSON key; the identity is the check key.
        $base = compatRekeyChecks($base);
        $current = compatRekeyChecks($current);
    } elseif ($id === 'commands') {
        $base = compatCommands($base);
        $current = compatCommands($current);
    } elseif ($id === 'config-defaults') {
        // A list default is one value: adding an element changes the default.
        $exactLists = ['azguard', 'azguard-filament'];
    }

    $breaks = [];
    $additions = [];
    compatDiff($id, $base, $current, '', $exactLists, $breaks, $additions);

    if ($id === 'commands') {
        // A new argument is compatible only when it is optional.
        foreach ($additions as $index => $addition) {
            if (preg_match('#^[^/]+/arguments/[^/]+$#', $addition['locator']) === 1 && ($addition['current']['required'] ?? false)) {
                $breaks[] = compatBreak('format.changed', $id, $addition['locator'], null, $addition['current']);
                unset($additions[$index]);
            }
        }
    }

    if (str_starts_with($id, 'ddl.') && ($context['new_migrations'] ?? []) === []) {
        // A schema object added below an existing host-key variant reaches existing installs only through a migration.
        foreach ($additions as $index => $addition) {
            if (substr_count($addition['locator'], '/') >= 1) {
                $breaks[] = compatBreak('format.ddl_unmigrated', $id, $addition['locator'], null, $addition['current']);
                unset($additions[$index]);
            }
        }
    }

    return ['breaks' => $breaks, 'additions' => array_values($additions)];
}

/**
 * @param  list<array<string, mixed>>  $breaks
 * @param  list<array<string, mixed>>  $additions
 * @param  list<string>  $exactLists  top-level keys under which lists are compared as whole values
 */
function compatDiff(string $id, mixed $base, mixed $current, string $path, array $exactLists, array &$breaks, array &$additions): void
{
    $join = static fn (string $key): string => $path === '' ? $key : $path.'/'.$key;
    $exact = $path !== '' && (in_array(explode('/', $path)[0], $exactLists, true) || compatIsOrderedList($id, $path));

    if (! is_array($base) || ! is_array($current) || $exact && (array_is_list($base) || array_is_list($current))) {
        if ($base !== $current) {
            $breaks[] = compatBreak('format.changed', $id, $path, $base, $current);
        }

        return;
    }

    if (array_is_list($base) && array_is_list($current)) {
        $keyed = compatListKeyed($base, $current);

        foreach ($keyed['base'] as $key => $entry) {
            if (! array_key_exists($key, $keyed['current'])) {
                $breaks[] = compatBreak('format.removed', $id, $join((string) $key), $entry, null);
            } elseif ($keyed['scalar'] === false) {
                compatDiff($id, $entry, $keyed['current'][$key], $join((string) $key), $exactLists, $breaks, $additions);
            }
        }
        foreach (array_diff_key($keyed['current'], $keyed['base']) as $key => $entry) {
            $additions[] = compatAddition('format.added', $id, $join((string) $key), $entry);
        }

        return;
    }

    foreach ($base as $key => $value) {
        if (! array_key_exists($key, $current)) {
            $breaks[] = compatBreak('format.removed', $id, $join((string) $key), $value, null);
        } else {
            compatDiff($id, $value, $current[$key], $join((string) $key), $exactLists, $breaks, $additions);
        }
    }
    foreach (array_diff_key($current, $base) as $key => $value) {
        $additions[] = compatAddition('format.added', $id, $join((string) $key), $value);
    }
}

/**
 * Lists whose element order is part of the frozen value: the columns of a DDL index (column order decides which lookups
 * the index serves and what a composite key identifies), unlike every other scalar list, which compares as a set.
 */
function compatIsOrderedList(string $id, string $path): bool
{
    return str_starts_with($id, 'ddl.') && preg_match('#/indexes/[^/]+/columns$#', $path) === 1;
}

/**
 * Keys the entries of two lists for comparison: scalars by value (a set), objects by the first identity column present
 * and unique in both lists, other objects by their whole content.
 *
 * @param  list<mixed>  $base
 * @param  list<mixed>  $current
 * @return array{base: array<string, mixed>, current: array<string, mixed>, scalar: bool}
 */
function compatListKeyed(array $base, array $current): array
{
    $entries = [...$base, ...$current];
    $scalar = array_filter($entries, is_array(...)) === [];
    $column = null;

    if (! $scalar) {
        foreach (COMPAT_LIST_KEYS as $candidate) {
            $unique = static function (array $list) use ($candidate): bool {
                $values = array_map(static fn (mixed $entry): mixed => is_array($entry) ? ($entry[$candidate] ?? null) : null, $list);

                return ! in_array(null, $values, true) && array_filter($values, is_scalar(...)) === $values
                    && count(array_unique(array_map(strval(...), $values))) === count($values);
            };

            if ($unique($base) && $unique($current)) {
                $column = $candidate;

                break;
            }
        }
    }

    $key = static function (mixed $entry) use ($scalar, $column): string {
        return match (true) {
            $scalar => is_string($entry) ? $entry : json_encode($entry, JSON_THROW_ON_ERROR),
            $column !== null => (string) $entry[$column],
            default => json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        };
    };
    $keyed = static function (array $list) use ($key): array {
        $result = [];
        foreach ($list as $entry) {
            $result[$key($entry)] = $entry;
        }

        return $result;
    };

    return ['base' => $keyed($base), 'current' => $keyed($current), 'scalar' => $scalar || $column === null];
}

/**
 * @param  array<mixed>  $fixture
 * @return array<mixed>
 */
function compatRekeyChecks(array $fixture): array
{
    $checks = [];

    foreach ($fixture['checks'] ?? [] as $fqcn => $check) {
        $checks[(string) ($check['key'] ?? $fqcn)] = $check;
    }
    $fixture['checks'] = $checks;

    return $fixture;
}

/**
 * The commands fixture with each synopsis parsed into its arguments and options.
 *
 * @param  array<mixed>  $fixture
 * @return array<mixed>
 */
function compatCommands(array $fixture): array
{
    foreach ($fixture as $name => $command) {
        if (is_array($command) && is_string($command['synopsis'] ?? null)) {
            $fixture[$name] = [...compatParseSynopsis($command['synopsis']), 'exit_codes' => $command['exit_codes'] ?? []];
        }
    }

    return $fixture;
}

/**
 * Parses a Symfony console synopsis (`InputDefinition::getSynopsis()`): `[-s|--opt]`, `[--opt OPT]`, `[--opt [OPT]]`,
 * `[--opt|--no-opt]`, `[--]`, `<arg>`, `[<arg>]`, `<arg>...`.
 *
 * @return array{arguments: array<string, array{position: int, required: bool, array: bool}>, options: array<string, array{shortcut: ?string, value: string, negatable: bool}>}
 */
function compatParseSynopsis(string $synopsis): array
{
    $arguments = [];
    $options = [];
    preg_match_all('/\[(?:-(\w[\w|]*)\|)?--([\w-]+)(?: (\[)?[A-Z0-9_-]+\]?)?(\|--no-[\w-]+)?\]|(\[)?<([\w-]+)>(\.\.\.)?|\[--\]|(\S+)/', $synopsis, $tokens, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

    foreach ($tokens as $token) {
        $token += array_fill(0, 9, null);

        if ($token[2] !== null) {
            $options[$token[2]] = [
                'shortcut' => $token[1],
                'value' => match (true) {
                    ! str_contains($token[0], ' ') => 'none',
                    $token[3] !== null => 'optional',
                    default => 'required',
                },
                'negatable' => $token[4] !== null,
            ];
        } elseif ($token[6] !== null) {
            $arguments[$token[6]] = ['position' => count($arguments), 'required' => $token[5] === null, 'array' => $token[7] !== null];
        } elseif ($token[8] !== null && trim($token[8], ']') !== '') {
            throw new CompatError("unparsable synopsis token {$token[8]} in: {$synopsis}");
        }
    }

    return ['arguments' => $arguments, 'options' => $options];
}

/**
 * The migration basenames a resources fixture names.
 *
 * @param  array<mixed>  $resources
 * @return list<string>
 */
function compatMigrationBasenames(array $resources): array
{
    $basenames = [];
    array_walk_recursive($resources, static function (mixed $value) use (&$basenames): void {
        if (is_string($value) && preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_\w+$/', $value) === 1) {
            $basenames[] = $value;
        }
    });

    return array_values(array_unique($basenames));
}

/* ---------------------------------------------------------------- CLI */

function compatRender(mixed $value): string
{
    if ($value === null) {
        return 'absent';
    }
    $text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return strlen($text) > 160 ? substr($text, 0, 157).'...' : $text;
}

/**
 * @param  list<string>  $argv
 */
function compatMain(string $root, array $argv): int
{
    $against = 'auto';
    $json = false;

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--json') {
            $json = true;
        } elseif (str_starts_with($argument, '--against=') && strlen($argument) > 10) {
            $against = substr($argument, 10);
        } else {
            fwrite(STDERR, "usage: php bin/api-compat.php [--against=<ref>|auto] [--json]\n");

            return 2;
        }
    }

    try {
        $specPath = $root.'/compatibility.json';
        $spec = is_file($specPath) ? json_decode((string) file_get_contents($specPath), true) : null;
        $list = compatArtifactList($spec, 'compatibility.json');
        /** @var array<string, mixed> $spec */
        $baseline = compatBaseline($root, $against, $spec);

        // A baseline before compatibility.json existed is read with the current artifact list; an artifact missing there
        // did not exist at the baseline.
        $baseSpec = compatGit($root, ['show', $baseline['sha'].':compatibility.json']);
        $baseList = $baseSpec === null ? $list : compatArtifactList(json_decode($baseSpec, true), "{$baseline['ref']}:compatibility.json");
        $base = compatCollect($baseList, static fn (string $path): ?string => compatGit($root, ['show', $baseline['sha'].':'.$path]), $baseSpec !== null, $baseline['ref']);
        $current = compatCollect($list, static fn (string $path): ?string => is_file($root.'/'.$path) ? (string) file_get_contents($root.'/'.$path) : null, false, 'working tree');
        $result = compatCompare($base, $current);
    } catch (CompatError|JsonException $error) {
        fwrite(STDERR, 'api-compat: '.$error->getMessage()."\n");

        return 2;
    }

    if ($json) {
        fwrite(STDOUT, json_encode(['baseline' => $baseline, ...$result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    } else {
        $title = "baseline {$baseline['ref']} ({$baseline['sha']})";

        if ($result['breaks'] === []) {
            fwrite(STDOUT, "compatible with {$title}: ".count($result['additions'])." addition(s)\n");
            foreach ($result['additions'] as $addition) {
                fwrite(STDOUT, "  + {$addition['rule']} {$addition['artifact']} {$addition['locator']}\n");
            }
        } else {
            fwrite(STDOUT, count($result['breaks'])." compatibility break(s) against {$title}:\n");
            foreach ($result['breaks'] as $break) {
                fwrite(STDOUT, "{$break['rule']} {$break['artifact']} {$break['locator']}: ".compatRender($break['baseline']).' -> '.compatRender($break['current'])."\n");
            }
        }
    }

    return $result['breaks'] === [] ? 0 : 1;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(compatMain(dirname(__DIR__), $argv));
}
