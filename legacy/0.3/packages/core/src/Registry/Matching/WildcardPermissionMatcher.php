<?php

declare(strict_types=1);

namespace AzGuard\Registry\Matching;

use AzGuard\Contracts\PermissionMatcher;
use Override;

/**
 * LEGACY wildcard matcher preserving AzGuard's historical 0.2 grammar: '*'
 * expands to '.*', so it crosses dot boundaries ('app.*' also matches
 * 'app.a.b'). Since 0.3.0 the default is {@see HierarchicalPermissionMatcher};
 * this grammar remains available for ONE deprecation cycle via
 * config('az-guard.features.wildcard_permission') = true and is scheduled for
 * removal together with that flag.
 */
final class WildcardPermissionMatcher implements PermissionMatcher
{
    /** @var array<string, string> Compiled regex keyed by pattern (memoized). */
    private array $compiled = [];

    #[Override]
    public function matches(string $pattern, string $key): bool
    {
        return preg_match($this->regexFor($pattern), $key) === 1;
    }

    private function regexFor(string $pattern): string
    {
        return $this->compiled[$pattern] ??= '/^'.str_replace(
            ['\\.', '\\*'],
            ['[.]', '.*'],
            preg_quote($pattern, '/'),
        ).'$/';
    }
}
