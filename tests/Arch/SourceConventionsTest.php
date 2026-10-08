<?php

declare(strict_types=1);

use AzGuard\Tests\Arch\SourceScan;

const AZGUARD_TASK_CODE = '/\bPLAN\d+\b|\bP\d+\.\d+\b|\b[CDNQRV]-?\d{2,3}\b|\bF\d{2}\b/';

/**
 * @return list<string> task codes found in comments of the given PHP code; code and strings are not scanned
 */
function taskCodesInComments(string $code): array
{
    $found = [];

    foreach (token_get_all($code) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
            && preg_match_all(AZGUARD_TASK_CODE, $token[1], $matches) > 0) {
            array_push($found, ...$matches[0]);
        }
    }

    return $found;
}

it('finds task codes only in comments', function (): void {
    $code = <<<'PHP'
        <?php
        // see P1.4 and PLAN2
        /** Closes N13, C-11, V117 and F01. */
        $ignored = 'P1.4 in a string';
        /* sha256, UTF-8, 0x21, ID64, P1 and 1.4 are not codes */
        PHP;

    expect(taskCodesInComments($code))->toBe(['P1.4', 'PLAN2', 'N13', 'C-11', 'V117', 'F01']);
});

it('keeps internal task codes out of source comments', function (): void {
    $offenders = [];

    foreach (SourceScan::files() as $file) {
        foreach (taskCodesInComments((string) file_get_contents($file)) as $code) {
            $offenders[] = basename($file).': '.$code;
        }
    }

    expect($offenders)->toBe([]);
});

const AZGUARD_CONFIG_READ = '/(?:\\bconfig\(|\bConfig::get\(|\bConfig::string\(|\bConfig::array\(|\bConfig::boolean\(|\bConfig::integer\()\s*[\'"]azguard/';

it('recognizes a read of the package configuration through the helper or the facade', function (string $code, bool $reads): void {
    expect(preg_match(AZGUARD_CONFIG_READ, $code) === 1)->toBe($reads);
})->with([
    'the helper' => ["config('azguard.defaults')", true],
    'the global helper' => ['\\config( "azguard.gate")', true],
    'the facade' => ["Config::get('azguard.gate.enabled')", true],
    'a typed facade read' => ["Config::boolean('azguard.gate.enabled')", true],
    'the config of another package' => ["config('app.name')", false],
    'a translation key' => ["\$translator->get('azguard::http.forbidden')", false],
]);

it('reads package configuration only in the configuration zone', function (): void {
    $offenders = array_values(array_filter(
        SourceScan::files(),
        static fn (string $file): bool => ! str_contains($file, '/packages/core/src/Configuration/')
            && preg_match(AZGUARD_CONFIG_READ, (string) file_get_contents($file)) === 1,
    ));

    expect($offenders)->toBe([]);
});

it('tags every contract as api or spi', function (): void {
    $untagged = array_values(array_filter(
        SourceScan::files(),
        static fn (string $file): bool => str_contains($file, '/packages/core/src/Contracts/')
            && preg_match('/^\s*\*\s*@(api|spi)\b/m', (string) file_get_contents($file)) !== 1,
    ));

    expect($untagged)->toBe([]);
});

/*
 * One rule picks a panel. The request panel and the default panel of a model are read only by the resolver and the
 * registry that owns them. The core provider binds the request panel, the facade root reads it for `currentPanel()`,
 * and `azguard.panel` sets it for a request to the panel the resolver returned for the route; none chooses a panel.
 * Any other entry point asks the resolver.
 */

const AZGUARD_PANEL_SELECTION_METHODS = ['defaultFor', 'forModel', 'azguardDefaultPanel'];

const AZGUARD_PANEL_SELECTION_OWNERS = [
    'defaultFor' => ['packages/core/src/Panels/PanelResolver.php', 'packages/core/src/Panels/PanelRegistry.php'],
    'forModel' => ['packages/core/src/Panels/PanelResolver.php', 'packages/core/src/Panels/PanelRegistry.php'],
    'azguardDefaultPanel' => ['packages/core/src/Panels/PanelResolver.php'],
    'CurrentPanel' => [
        'packages/core/src/Panels/PanelResolver.php', 'packages/core/src/Panels/CurrentPanel.php',
        'packages/core/src/AzGuardServiceProvider.php', 'packages/core/src/AzGuardManager.php',
        'packages/core/src/Laravel/Http/Middleware/EnterPanel.php',
    ],
];

/**
 * @return list<string> panel selection methods called in the PHP code and `CurrentPanel` when the class is named;
 *                      declarations, strings and comments are not uses
 */
function panelSelectionUses(string $code): array
{
    $tokens = array_values(array_filter(
        token_get_all($code),
        static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $uses = [];

    foreach ($tokens as $i => $token) {
        if (! is_array($token)) {
            continue;
        }

        $previous = $tokens[$i - 1] ?? null;
        $accessed = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);

        if ($token[0] === T_STRING && $accessed && ($tokens[$i + 1] ?? null) === '('
            && in_array($token[1], AZGUARD_PANEL_SELECTION_METHODS, true)) {
            $uses[] = $token[1];
        }

        if (! $accessed && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && preg_match('/(\A|\\\\)CurrentPanel\z/', $token[1]) === 1) {
            $uses[] = 'CurrentPanel';
        }
    }

    return array_values(array_unique($uses));
}

it('finds panel selection calls and the request panel class but not declarations, strings or comments', function (): void {
    $code = <<<'PHP'
        <?php
        namespace AzGuard\X;
        use AzGuard\Panels\CurrentPanel;
        final class Entry
        {
            public function __construct(private CurrentPanel $current) {}
            public function defaultFor(): void {}
            public function pick($registry, $user): void
            {
                $registry->defaultFor($user::class);
                $registry?->forModel($user::class);
                $user->azguardDefaultPanel();
                $registry->currentPanel();
                // $registry->forModel() in a comment
                $name = 'azguardDefaultPanel';
            }
        }
        PHP;

    expect(panelSelectionUses($code))->toBe(['CurrentPanel', 'defaultFor', 'forModel', 'azguardDefaultPanel']);
});

it('picks a panel only in the panel resolver', function (): void {
    $root = dirname(__DIR__, 2).'/';
    $offenders = [];

    foreach (SourceScan::files() as $file) {
        $path = str_replace($root, '', $file);

        foreach (panelSelectionUses((string) file_get_contents($file)) as $use) {
            if (! in_array($path, AZGUARD_PANEL_SELECTION_OWNERS[$use], true)) {
                $offenders[] = $path.': '.$use;
            }
        }
    }

    expect($offenders)->toBe([]);
});
