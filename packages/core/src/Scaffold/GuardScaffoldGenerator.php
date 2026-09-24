<?php

declare(strict_types=1);

namespace AzGuard\Scaffold;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Shared validation, rendering, conflict detection and atomic writes for
 * make:guard-panel and make:guard-domain.
 */
final class GuardScaffoldGenerator
{
    private const STUB_DIR = __DIR__.'/../../stubs/panel/';

    public function __construct(
        private readonly Command $command,
    ) {}

    /**
     * @return array{replacements: array<string, string>, targets: list<array{path: string, content: string, allowUpdate?: bool, expectedOriginal?: string}>}|null
     */
    public function planNewPanel(
        string $panel,
        string $domain,
        string $pathOption,
        string $roleName,
        bool $withAbilities,
        ?string $modelOption,
        ?string $actorOption,
    ): ?array {
        if (! $this->assertPhpIdentifier(name: $panel, label: 'panel')) {
            return null;
        }

        if (! $this->assertPhpIdentifier(name: $domain, label: 'domain')) {
            return null;
        }

        if (! $this->assertPhpIdentifier(name: $roleName, label: 'role')) {
            return null;
        }

        if (! $this->assertSafeRelativePath(path: $pathOption)) {
            return null;
        }

        $basePath = $this->guardBasePath(path: $pathOption, panel: $panel);

        if (is_link($basePath)) {
            $this->command->error("Scaffold panel path contains a symlink: {$basePath}.");

            return null;
        }

        $modelFqcn = $this->resolvePanelModel(domain: $domain, modelOption: $modelOption);

        if ($modelFqcn === null) {
            return null;
        }

        $actorFqcn = $this->resolveActor(actorOption: $actorOption);

        if ($actorFqcn === null) {
            return null;
        }

        $replacements = $this->buildReplacements(
            pathOption: $pathOption,
            panel: $panel,
            domain: $domain,
            roleName: $roleName,
            modelFqcn: $modelFqcn,
            actorFqcn: $actorFqcn,
        );

        $targets = $this->domainFileTargets(
            basePath: $basePath,
            domain: $domain,
            withAbilities: $withAbilities,
            replacements: $replacements,
            includeProvider: true,
            includeRole: true,
            panel: $panel,
        );

        if (File::isDirectory(directory: $basePath)) {
            $providerPath = "{$basePath}/{$panel}GuardPanelProvider.php";
            $expectedProvider = $targets[0]['content'];

            if (! File::isFile($providerPath) || File::get(path: $providerPath) !== $expectedProvider) {
                $this->command->error("Panel already exists: {$basePath}. Use make:guard-domain for additional domains.");

                return null;
            }
        }

        $configTarget = $this->panelConfigTarget(
            providerFqcn: $replacements['namespace'].'\\'.$panel.'GuardPanelProvider',
        );

        if ($configTarget === false) {
            return null;
        }

        if ($configTarget !== null) {
            $targets[] = $configTarget;
        }

        return ['replacements' => $replacements, 'targets' => $targets];
    }

    /**
     * @return array{replacements: array<string, string>, targets: list<array{path: string, content: string, allowUpdate?: bool, expectedOriginal?: string}>, providerPath: string, providerFqcn: string, permissionFqcn: string}|null
     */
    public function planAddDomain(
        string $panel,
        string $domain,
        string $pathOption,
        bool $withAbilities,
        ?string $modelOption,
        ?string $actorOption,
    ): ?array {
        if (! $this->assertPhpIdentifier(name: $panel, label: 'panel')) {
            return null;
        }

        if (! $this->assertPhpIdentifier(name: $domain, label: 'domain')) {
            return null;
        }

        if (! $this->assertSafeRelativePath(path: $pathOption)) {
            return null;
        }

        $basePath = $this->guardBasePath(path: $pathOption, panel: $panel);

        if (is_link($basePath)) {
            $this->command->error("Scaffold panel path contains a symlink: {$basePath}.");

            return null;
        }
        $panelId = Str::lower(value: $panel);
        $domainKey = Str::snake(value: $domain);

        if (! File::isDirectory(directory: $basePath)) {
            $this->command->error("Panel not found at {$basePath}. Create it with make:guard-panel first.");

            return null;
        }

        $providerPath = "{$basePath}/{$panel}GuardPanelProvider.php";

        if (! is_file($providerPath)) {
            $this->command->error("Panel provider missing: {$providerPath}.");

            return null;
        }

        $modelFqcn = $this->resolveRequiredDomainModel(
            panelId: $panelId,
            domainKey: $domainKey,
            modelOption: $modelOption,
        );

        if ($modelFqcn === null) {
            return null;
        }

        $actorFqcn = $this->resolveActor(actorOption: $actorOption);

        if ($actorFqcn === null) {
            return null;
        }

        $replacements = $this->buildReplacements(
            pathOption: $pathOption,
            panel: $panel,
            domain: $domain,
            roleName: 'Admin',
            modelFqcn: $modelFqcn,
            actorFqcn: $actorFqcn,
        );

        $targets = $this->domainFileTargets(
            basePath: $basePath,
            domain: $domain,
            withAbilities: $withAbilities,
            replacements: $replacements,
            includeProvider: false,
            includeRole: false,
            panel: $panel,
        );

        $baseNamespace = $this->guardBaseNamespace(path: $pathOption, panel: $panel);
        $permissionFqcn = "{$baseNamespace}\\{$domain}\\Permissions\\{$domain}Permission";

        return [
            'replacements' => $replacements,
            'targets' => $targets,
            'providerPath' => $providerPath,
            'providerFqcn' => $baseNamespace.'\\'.$panel.'GuardPanelProvider',
            'permissionFqcn' => $permissionFqcn,
        ];
    }

    /**
     * @param  list<array{path: string, content: string, allowUpdate?: bool, expectedOriginal?: string}>  $targets
     */
    public function writeTargets(array $targets, bool $force): bool
    {
        $pending = [];
        $originals = [];
        $conflicts = [];

        foreach ($targets as $target) {
            $path = $target['path'];
            $content = $target['content'];
            $this->assertNoSymlink($path);

            if (file_exists($path) && ! is_file($path)) {
                $conflicts[] = $path;

                continue;
            }

            $originals[$path] = is_file($path) ? File::get(path: $path) : null;

            if (array_key_exists('expectedOriginal', $target) && $originals[$path] !== $target['expectedOriginal']) {
                $conflicts[] = $path;

                continue;
            }

            if ($originals[$path] === $content) {
                continue;
            }

            if ($originals[$path] !== null && ! $force && ! ($target['allowUpdate'] ?? false)) {
                $conflicts[] = $path;

                continue;
            }

            $pending[$path] = $content;
        }

        if ($conflicts !== []) {
            $this->command->error('Conflicting generated files (use --force on owned targets only):');

            foreach ($conflicts as $path) {
                $this->command->line("  - {$path}");
            }

            return false;
        }

        if ($pending === []) {
            $this->command->info('No changes — generated files already match.');

            return true;
        }

        return $this->commitWrites(writes: $pending, originals: $originals);
    }

    /**
     * Return the provider update as a target so domain files and registration
     * are preflighted and committed together.
     *
     * @return array{path: string, content: string, allowUpdate: bool, expectedOriginal: string}|null
     */
    public function permissionProviderTarget(string $providerPath, string $permissionFqcn): ?array
    {
        $contents = File::get(path: $providerPath);
        $namespace = substr($permissionFqcn, 0, strrpos($permissionFqcn, '\\'));
        $namespace = substr($namespace, 0, strrpos($namespace, '\\'));
        $namespace = substr($namespace, 0, strrpos($namespace, '\\'));
        $panel = substr(class_basename($providerPath), 0, -strlen('GuardPanelProvider.php'));
        $baseNamespace = $namespace;

        if (! $this->isRecognizedGeneratedProvider($contents, $baseNamespace, $panel)) {
            $this->command->error("Provider is not a recognized generated template: {$providerPath}. Add \\{$permissionFqcn}::class to ->permissionEnums([...]) manually.");

            return null;
        }

        if (str_contains($contents, "\\{$permissionFqcn}::class")
            || (str_contains($contents, "use {$permissionFqcn};")
                && str_contains($contents, class_basename($permissionFqcn).'::class'))) {
            return ['path' => $providerPath, 'content' => $contents, 'allowUpdate' => true, 'expectedOriginal' => $contents];
        }

        $needle = '->permissionEnums([';
        $insertAt = strpos($contents, $needle) + strlen($needle);
        $updated = substr($contents, 0, $insertAt)."\n                \\{$permissionFqcn}::class,".substr($contents, $insertAt);

        return ['path' => $providerPath, 'content' => $updated, 'allowUpdate' => true, 'expectedOriginal' => $contents];
    }

    private function isRecognizedGeneratedProvider(string $contents, string $namespace, string $panel): bool
    {
        if (! str_contains($contents, '// azguard:generated-provider')) {
            return false;
        }

        if (! preg_match('/^use '.preg_quote($namespace, '/').'\\\\([A-Za-z_][A-Za-z0-9_]*)\\\\Permissions\\\\\\1Permission;$/m', $contents, $match)) {
            return false;
        }

        $replacements = [
            'namespace' => $namespace,
            'panel' => $panel,
            'panelId' => Str::lower(value: $panel),
            'domain' => $match[1],
        ];
        $expected = $this->renderStub(stubName: 'guardpanelprovider', replacements: $replacements);
        $addedEnumPattern = '/^                \\\\'.preg_quote($namespace, '/').'\\\\([A-Za-z_][A-Za-z0-9_]*)\\\\Permissions\\\\\\1Permission::class,\n/m';
        $withoutAddedEnums = preg_replace($addedEnumPattern, '', $contents);

        return $withoutAddedEnums === $expected;
    }

    private function assertNoSymlink(string $path): void
    {
        $cursor = $path;

        while ($cursor !== base_path() && str_starts_with($cursor, base_path().DIRECTORY_SEPARATOR)) {
            if (is_link($cursor)) {
                throw new InvalidArgumentException("Scaffold path contains a symlink: {$cursor}.");
            }

            $cursor = dirname($cursor);
        }
    }

    /**
     * @return array{path: string, content: string, allowUpdate: bool, expectedOriginal: string}|null
     */
    private function panelConfigTarget(string $providerFqcn): array|false|null
    {
        $configPath = config_path('az-guard.php');

        if (! File::exists(path: $configPath)) {
            $this->command->warn("Add \\{$providerFqcn}::class to the 'panels' array in config/az-guard.php (config not published).");

            return null;
        }

        $contents = File::get(path: $configPath);

        if (! $this->isRecognizedPanelsConfig(contents: $contents)) {
            $this->command->error("Unrecognized config/az-guard.php panels layout. Add \\{$providerFqcn}::class to 'panels' manually, then retry.");

            return false;
        }

        preg_match("/'panels'\\s*=>\\s*\\[([^\\[\\]]*)\\]/s", $contents, $matches);

        if (str_contains($matches[1], "{$providerFqcn}::class")) {
            return [
                'path' => $configPath,
                'content' => $contents,
                'allowUpdate' => true,
                'expectedOriginal' => $contents,
            ];
        }

        $updated = preg_replace(
            pattern: '/(\'panels\'\s*=>\s*\[)/',
            replacement: "$1\n        \\\\{$providerFqcn}::class,",
            subject: $contents,
            limit: 1,
        );

        if (! is_string($updated)) {
            throw new RuntimeException('Could not prepare panels config registration.');
        }

        return [
            'path' => $configPath,
            'content' => $updated,
            'allowUpdate' => true,
            'expectedOriginal' => $contents,
        ];
    }

    /**
     * @param  array<string, string>  $replacements
     * @return list<array{path: string, content: string}>
     */
    private function domainFileTargets(
        string $basePath,
        string $domain,
        bool $withAbilities,
        array $replacements,
        bool $includeProvider,
        bool $includeRole,
        string $panel,
    ): array {
        $domainPath = $this->domainPath(basePath: $basePath, domain: $domain);
        $targets = [];

        if ($includeProvider) {
            $targets[] = [
                'path' => "{$basePath}/{$panel}GuardPanelProvider.php",
                'content' => $this->renderStub(stubName: 'guardpanelprovider', replacements: $replacements),
            ];
            $targets[] = [
                'path' => "{$basePath}/Roles/{$replacements['name']}Role.php",
                'content' => $this->renderStub(stubName: 'role', replacements: $replacements),
            ];
        }

        $targets[] = [
            'path' => "{$domainPath}/Permissions/{$domain}Permission.php",
            'content' => $this->renderStub(stubName: 'domain-permission', replacements: $replacements),
        ];
        $targets[] = [
            'path' => "{$domainPath}/Policies/{$domain}Policy.php",
            'content' => $this->renderStub(stubName: 'domain-policy', replacements: $replacements),
        ];

        if ($withAbilities) {
            $targets[] = [
                'path' => "{$domainPath}/Abilities/{$domain}Abilities.php",
                'content' => $this->renderStub(stubName: 'domain-abilities', replacements: $replacements),
            ];
        }

        return $targets;
    }

    /**
     * @return array<string, string>
     */
    private function buildReplacements(
        string $pathOption,
        string $panel,
        string $domain,
        string $roleName,
        string $modelFqcn,
        string $actorFqcn,
    ): array {
        $baseNamespace = $this->guardBaseNamespace(path: $pathOption, panel: $panel);
        $panelId = Str::lower(value: $panel);
        $domainKey = Str::snake(value: $domain);
        $modelShort = class_basename(class: $modelFqcn);
        $actorShort = class_basename(class: $actorFqcn);

        return [
            'namespace' => $baseNamespace,
            'panel' => $panel,
            'panelId' => $panelId,
            'domain' => $domain,
            'domainKey' => $domainKey,
            'name' => $roleName,
            'nameLower' => Str::lower(value: $roleName),
            'modelFqcn' => $modelFqcn,
            'modelShort' => $modelShort,
            'actorFqcn' => $actorFqcn,
            'actorShort' => $actorShort,
        ];
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function renderStub(string $stubName, array $replacements): string
    {
        $stubPath = self::STUB_DIR.$stubName.'.stub';
        $content = File::get(path: $stubPath);

        foreach ($replacements as $key => $value) {
            $content = str_replace(search: '{{ '.$key.' }}', replace: $value, subject: $content);
        }

        return $content;
    }

    private function resolvePanelModel(string $domain, ?string $modelOption): ?string
    {
        if ($modelOption !== null && $modelOption !== '') {
            return $this->validateModelFqcn(fqcn: $modelOption);
        }

        $legacy = 'App\\Models\\'.$domain;

        $this->command->warn(
            "No --model option: using unvalidated legacy convention {$legacy}. Prefer --model= with an existing Eloquent model FQCN.",
        );

        return $legacy;
    }

    private function resolveRequiredDomainModel(string $panelId, string $domainKey, ?string $modelOption): ?string
    {
        if ($modelOption !== null && $modelOption !== '') {
            return $this->validateModelFqcn(fqcn: $modelOption);
        }

        /** @var mixed $configured */
        $configured = config(key: "az-guard.scaffold.domain_models.{$panelId}.{$domainKey}");

        if (is_string($configured) && $configured !== '') {
            return $this->validateModelFqcn(fqcn: $configured);
        }

        $this->command->error('Domain model is required: pass --model= with an existing Eloquent model FQCN or set az-guard.scaffold.domain_models.');

        return null;
    }

    private function validateModelFqcn(string $fqcn): ?string
    {
        $fqcn = $this->validFqcn($fqcn, 'model');

        if ($fqcn === null) {
            return null;
        }

        if (! class_exists($fqcn)) {
            $this->command->error("Model class does not exist: {$fqcn}.");

            return null;
        }

        if (! is_subclass_of($fqcn, Model::class)) {
            $this->command->error("Model class must extend Illuminate\\Database\\Eloquent\\Model: {$fqcn}.");

            return null;
        }

        return $fqcn;
    }

    private function resolveActor(?string $actorOption): ?string
    {
        $fqcn = $actorOption;

        if ($fqcn === null || $fqcn === '') {
            $guard = (string) config(key: 'auth.defaults.guard', default: 'web');
            $provider = (string) config(key: "auth.guards.{$guard}.provider", default: 'users');
            $fqcn = config(key: "auth.providers.{$provider}.model");
        }

        if (! is_string($fqcn) || $fqcn === '') {
            $this->command->error('Actor FQCN is required: pass --actor= or configure auth.providers.users.model.');

            return null;
        }

        $fqcn = $this->validFqcn($fqcn, 'actor');

        if ($fqcn === null) {
            return null;
        }

        if (! class_exists($fqcn)) {
            $this->command->error("Actor class does not exist: {$fqcn}.");

            return null;
        }

        if (! is_subclass_of($fqcn, Authenticatable::class)) {
            $this->command->error("Actor class must implement Illuminate\\Contracts\\Auth\\Authenticatable: {$fqcn}.");

            return null;
        }

        return $fqcn;
    }

    private function assertPhpIdentifier(string $name, string $label): bool
    {
        if (preg_match(pattern: '/^[A-Za-z_][A-Za-z0-9_]*$/D', subject: $name) === 1
            && ! in_array(strtolower($name), ['abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'false', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'int', 'float', 'bool', 'iterable', 'implements', 'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match', 'mixed', 'namespace', 'never', 'new', 'null', 'object', 'or', 'parent', 'print', 'private', 'protected', 'public', 'readonly', 'require', 'require_once', 'return', 'self', 'static', 'string', 'switch', 'throw', 'trait', 'true', 'try', 'unset', 'use', 'var', 'void', 'while', 'xor', 'yield'], true)) {
            return true;
        }

        $this->command->error("Invalid {$label} identifier: {$name}.");

        return false;
    }

    private function assertSafeRelativePath(string $path): bool
    {
        if (! str_starts_with($path, 'app/') || str_starts_with($path, '/') || str_ends_with($path, '/')
            || str_contains($path, '\\') || str_contains($path, '//')) {
            $this->command->error("Unsafe --path value: {$path}. Use a relative app/... directory.");

            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if (! $this->assertPhpIdentifier(name: $segment, label: 'path segment')) {
                return false;
            }
        }

        $cursor = base_path();

        foreach (explode('/', $path) as $segment) {
            $cursor .= '/'.$segment;

            if (is_link($cursor)) {
                $this->command->error("Scaffold path contains a symlink: {$cursor}.");

                return false;
            }
        }

        return true;
    }

    private function validFqcn(string $fqcn, string $label): ?string
    {
        $class = ltrim($fqcn, '\\');

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $class)) {
            $this->command->error("Invalid {$label} FQCN: {$fqcn}.");

            return null;
        }

        return $class;
    }

    private function guardBasePath(string $path, string $panel): string
    {
        return base_path(trim(string: $path, characters: '/').'/'.$panel);
    }

    private function guardBaseNamespace(string $path, string $panel): string
    {
        $namespacePath = preg_replace(pattern: '/^app\b/i', replacement: 'App', subject: trim(string: $path, characters: '/'));

        return str_replace(search: '/', replace: '\\', subject: (string) $namespacePath).'\\'.$panel;
    }

    private function domainPath(string $basePath, string $domain): string
    {
        return $basePath.'/'.$domain;
    }

    /**
     * @param  array<string, string>  $writes
     * @param  array<string, string|null>  $originals
     */
    private function commitWrites(array $writes, array $originals): bool
    {
        $written = [];
        $temporary = null;

        try {
            foreach ($writes as $path => $content) {
                File::ensureDirectoryExists(path: dirname(path: $path));

                if ((is_file($path) ? File::get(path: $path) : null) !== $originals[$path]) {
                    throw new RuntimeException("Scaffold target changed during generation: {$path}.");
                }

                $temporary = tempnam(dirname($path), '.azguard-');

                if ($temporary === false || File::put(path: $temporary, contents: $content) === false || ! rename($temporary, $path)) {
                    throw new RuntimeException("Could not atomically write scaffold target: {$path}.");
                }

                $temporary = null;
                $written[] = $path;
            }

            return true;
        } catch (Throwable $throwable) {
            if (is_string($temporary) && is_file($temporary)) {
                File::delete(paths: $temporary);
            }

            foreach (array_reverse($written) as $path) {
                if ($originals[$path] === null) {
                    File::delete(paths: $path);
                } else {
                    File::put(path: $path, contents: $originals[$path]);
                }
            }

            $this->command->error('Scaffold write failed: '.$throwable->getMessage());

            return false;
        }
    }

    private function isRecognizedPanelsConfig(string $contents): bool
    {
        preg_match_all("/'panels'\\s*=>\\s*\\[([^\\[\\]]*)\\]/s", $contents, $matches);

        if (count($matches[0]) !== 1
            || preg_match('/^(?:\s*\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*::class\s*,?\s*)*$/D', $matches[1][0]) !== 1) {
            return false;
        }

        $minimal = preg_match(
            '/\A<\?php\s*return\s*\[\s*\'panels\'\s*=>\s*\[[^\[\]]*\]\s*,?\s*\]\s*;\s*\z/s',
            $contents,
        ) === 1;

        $published = str_contains($contents, "// azguard:generated-config-panels\n    'panels' => [")
            && str_contains($contents, 'return [');

        return $minimal || $published;
    }
}
