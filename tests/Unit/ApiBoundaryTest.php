<?php

declare(strict_types=1);

/**
 * Enforces the @api/@internal SemVer boundary (F10) at the source level, since
 * PHPStan's native @internal check only fires across composer packages and would
 * miss an internal type leaking into a public signature within core.
 *
 *  1. Every published contract (Contracts/, Registry/Contracts/) must carry @api.
 *  2. No @api type may reference an @internal type in a public method signature.
 *  3. The @api surface (types + public method signatures, parameter names
 *     included) matches the committed snapshot fixture — the surface-freeze
 *     gate (P3.2, D20). Any drift — a type or method appearing, disappearing,
 *     or changing signature — turns this red, including BC-safe additions:
 *     pre-1.0 over-sensitivity is deliberate. Regenerating the fixture is a
 *     conscious act that requires a D# decision entry plus a version-bump
 *     call, never a reflex to silence the test:
 *
 *         composer test:api-snapshot:update
 */
$coreRoot = dirname(__DIR__, 2).'/packages/core/src';

/** @return list<class-string> */
$classesIn = function (string $subdir) use ($coreRoot): array {
    $dir = "$coreRoot/$subdir";
    $out = [];

    if (! is_dir($dir)) {
        return $out;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($coreRoot) + 1, -4);
        /** @var class-string $fqcn */
        $fqcn = 'AzGuard\\'.str_replace('/', '\\', $relative);
        $out[] = $fqcn;
    }

    return $out;
};

$hasTag = function (string $fqcn, string $tag): bool {
    $doc = (new ReflectionClass($fqcn))->getDocComment();

    // Matches both multi-line (" * @api") and single-line ("/** @api */") docblocks.
    return is_string($doc) && preg_match('/'.preg_quote($tag, '/').'\b/', $doc) === 1;
};

/**
 * Docblock-declared facade surface: `@method` lines up to (not including) the
 * `--- @internal` section marker, reduced to their signature part — the
 * trailing free-text description is not part of the frozen contract.
 *
 * @return list<string>
 */
$docblockMethodSignatures = function (string $fqcn): array {
    $doc = (new ReflectionClass($fqcn))->getDocComment();

    if (! is_string($doc)) {
        return [];
    }

    $out = [];

    foreach (preg_split('/\R/', $doc) ?: [] as $line) {
        if (str_contains($line, '--- @internal')) {
            break;
        }

        if (preg_match('/@method\s+(.+)$/', $line, $m) !== 1) {
            continue;
        }

        $rest = trim($m[1]);

        // The method name is the first bare word glued to "(" — parameter names
        // are "$"-prefixed and generics use "<>", so this cannot match inside a type.
        if (preg_match('/[A-Za-z_]\w*\(/', $rest, $name, PREG_OFFSET_CAPTURE) !== 1) {
            continue;
        }

        // Walk to the balanced closing paren: docblock types themselves contain
        // parens ("(string | UnitEnum) $key"), so a greedy regex would misfire.
        $depth = 0;
        $end = null;

        for ($i = $name[0][1] + strlen($name[0][0]) - 1, $len = strlen($rest); $i < $len; $i++) {
            if ($rest[$i] === '(') {
                $depth++;
            } elseif ($rest[$i] === ')' && --$depth === 0) {
                $end = $i;

                break;
            }
        }

        if ($end === null) {
            continue;
        }

        $out[] = (string) preg_replace('/\s+/', ' ', substr($rest, 0, $end + 1));
    }

    sort($out);

    return $out;
};

/**
 * Reflection-declared public methods of the type itself: engine-provided
 * methods (enum cases() etc.) and methods tagged @internal in their own
 * docblock stay outside the frozen surface. Parameter NAMES are part of the
 * signature — the project treats named arguments as contract.
 *
 * PHP 8.5 resolves a same-class `self` reflection type to the declaring FQCN,
 * while older supported engines render `self`. The snapshot freezes the source
 * contract, so canonicalize that engine-only representation before comparing it.
 *
 * @return list<string>
 */
$publicMethodSignatures = function (string $fqcn): array {
    $out = [];

    foreach ((new ReflectionClass($fqcn))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $fqcn || $method->getFileName() === false) {
            continue;
        }

        $doc = $method->getDocComment();

        if (is_string($doc) && preg_match('/@internal\b/', $doc) === 1) {
            continue;
        }

        $renderType = static function (ReflectionType $type) use ($method): string {
            if ($type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
                && $type->getName() === $method->getDeclaringClass()->getName()) {
                return ($type->allowsNull() ? '?' : '').'self';
            }

            return (string) $type;
        };

        $params = [];

        foreach ($method->getParameters() as $param) {
            $rendered = $param->getType() instanceof ReflectionType ? $renderType($param->getType()).' ' : '';
            $rendered .= $param->isPassedByReference() ? '&' : '';
            $rendered .= $param->isVariadic() ? '...' : '';
            $rendered .= '$'.$param->getName();
            // Presence only (D20): the default's value is behavior, not signature.
            $rendered .= $param->isDefaultValueAvailable() ? ' = default' : '';

            $params[] = $rendered;
        }

        $signature = ($method->isStatic() ? 'static ' : '').$method->getName().'('.implode(', ', $params).')';

        if ($method->getReturnType() instanceof ReflectionType) {
            $signature .= ': '.$renderType($method->getReturnType());
        }

        $out[] = $signature;
    }

    sort($out);

    return $out;
};

/** @return array<class-string, array{kind: string, docblockMethods?: list<string>, methods: list<string>}> */
$apiSurface = function () use ($classesIn, $hasTag, $docblockMethodSignatures, $publicMethodSignatures): array {
    $surface = [];

    foreach ($classesIn('') as $fqcn) {
        if (! (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn) || trait_exists($fqcn))) {
            continue;
        }

        if (! $hasTag($fqcn, '@api')) {
            continue;
        }

        $reflection = new ReflectionClass($fqcn);
        $entry = [
            'kind' => match (true) {
                $reflection->isEnum() => 'enum',
                $reflection->isInterface() => 'interface',
                $reflection->isTrait() => 'trait',
                default => 'class',
            },
        ];

        $docblockMethods = $docblockMethodSignatures($fqcn);

        if ($docblockMethods !== []) {
            $entry['docblockMethods'] = $docblockMethods;
        }

        $entry['methods'] = $publicMethodSignatures($fqcn);
        $surface[$fqcn] = $entry;
    }

    ksort($surface);

    return $surface;
};

$snapshotPath = dirname(__DIR__).'/Fixtures/api-surface.snapshot.php';

$exportSnapshot = function (array $surface): string {
    $lines = [
        '<?php',
        '',
        '/**',
        ' * Committed snapshot of the public @api surface of packages/core (P3.2, D20).',
        ' *',
        ' * DO NOT edit by hand and DO NOT regenerate just to silence a red',
        ' * ApiBoundaryTest: after the P3 freeze, every surface change goes through an',
        ' * explicit D# decision entry with bump semantics (root/semver-policy.md, P3.3).',
        ' * Deliberate regeneration: composer test:api-snapshot:update',
        ' */',
        '',
        'return [',
    ];

    foreach ($surface as $fqcn => $entry) {
        $lines[] = "    '".addslashes($fqcn)."' => [";
        $lines[] = "        'kind' => '".$entry['kind']."',";

        foreach (['docblockMethods', 'methods'] as $key) {
            if (! isset($entry[$key])) {
                continue;
            }

            if ($entry[$key] === []) {
                $lines[] = "        '{$key}' => [],";

                continue;
            }

            $lines[] = "        '{$key}' => [";

            foreach ($entry[$key] as $signature) {
                $lines[] = "            '".addslashes($signature)."',";
            }

            $lines[] = '        ],';
        }

        $lines[] = '    ],';
    }

    $lines[] = '];';

    return implode("\n", $lines)."\n";
};

test('every published contract carries @api', function () use ($classesIn, $hasTag) {
    $contracts = [...$classesIn('Contracts'), ...$classesIn('Registry/Contracts')];

    expect($contracts)->not->toBeEmpty();

    foreach ($contracts as $fqcn) {
        expect(class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn))->toBeTrue();
        expect($hasTag($fqcn, '@api'))->toBeTrue("Published contract [{$fqcn}] must declare @api.");
    }
});

test('no @api type references an @internal type in a public signature', function () use ($classesIn, $hasTag) {
    // Collect every @internal AzGuard type across core.
    $allCore = $classesIn('');
    $internal = [];

    foreach ($allCore as $fqcn) {
        if ((class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn)) && $hasTag($fqcn, '@internal')) {
            $internal[$fqcn] = true;
        }
    }

    expect($internal)->not->toBeEmpty();

    $referencedInternal = static function (?ReflectionType $type) use ($internal): array {
        if ($type === null) {
            return [];
        }

        $named = $type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType
            ? $type->getTypes()
            : [$type];

        $leaked = [];

        foreach ($named as $t) {
            if ($t instanceof ReflectionNamedType && ! $t->isBuiltin() && isset($internal[$t->getName()])) {
                $leaked[] = $t->getName();
            }
        }

        return $leaked;
    };

    foreach ($allCore as $fqcn) {
        if (! (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn)) || ! $hasTag($fqcn, '@api')) {
            continue;
        }

        $reflection = new ReflectionClass($fqcn);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }

            $leaks = $referencedInternal($method->getReturnType());

            foreach ($method->getParameters() as $param) {
                $leaks = [...$leaks, ...$referencedInternal($param->getType())];
            }

            expect($leaks)->toBe([], "@api {$fqcn}::{$method->getName()}() leaks @internal type(s): ".implode(', ', $leaks));
        }
    }
});

test('the @api surface matches the committed snapshot fixture', function () use ($apiSurface, $snapshotPath, $exportSnapshot) {
    $actual = $apiSurface();

    expect($actual)->not->toBeEmpty();

    if (getenv('AZ_UPDATE_API_SNAPSHOT') === '1') {
        file_put_contents($snapshotPath, $exportSnapshot($actual));
    }

    expect(is_file($snapshotPath))->toBeTrue(
        "Missing snapshot fixture [{$snapshotPath}] — generate it deliberately (D# + bump): composer test:api-snapshot:update",
    );

    /** @var array<class-string, array{kind: string, docblockMethods?: list<string>, methods: list<string>}> $expected */
    $expected = require $snapshotPath;

    $drift = [];

    foreach (array_diff_key($expected, $actual) as $fqcn => $entry) {
        $drift[] = "type removed from the surface: {$fqcn}";
    }

    foreach (array_diff_key($actual, $expected) as $fqcn => $entry) {
        $drift[] = "type added to the surface: {$fqcn}";
    }

    foreach (array_intersect_key($expected, $actual) as $fqcn => $entry) {
        if ($entry['kind'] !== $actual[$fqcn]['kind']) {
            $drift[] = "kind changed on {$fqcn}: {$entry['kind']} -> {$actual[$fqcn]['kind']}";
        }

        foreach (['docblockMethods', 'methods'] as $key) {
            $was = $entry[$key] ?? [];
            $now = $actual[$fqcn][$key] ?? [];

            foreach (array_diff($was, $now) as $signature) {
                $drift[] = "{$fqcn} lost {$key}: {$signature}";
            }

            foreach (array_diff($now, $was) as $signature) {
                $drift[] = "{$fqcn} gained {$key}: {$signature}";
            }
        }
    }

    expect($drift)->toBe(
        [],
        "Public @api surface drifted from the committed snapshot (a signature change shows as lost+gained):\n - "
        .implode("\n - ", $drift)
        ."\nIf (and only if) the drift is a deliberate, D#-recorded decision: composer test:api-snapshot:update",
    );
});
