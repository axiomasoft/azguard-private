<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Scaffold;

/**
 * @internal The string-backed enum a PHP source declares, read as text: the generators look at files that are not
 * loaded and may not be loadable yet.
 */
final readonly class EnumSource
{
    /**
     * @param  class-string  $class
     * @param  list<string>  $cases  the names of the cases
     * @param  bool  $policyOnly  whether the enum is declared `#[PolicyOnly]`
     */
    private function __construct(public string $class, public array $cases, public bool $policyOnly = false) {}

    public static function read(string $source): ?self
    {
        if (preg_match('/^enum\s+(\w+)\s*:\s*string\b/m', $source, $enum) !== 1) {
            return null;
        }
        $namespace = preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $source, $declared) === 1 ? $declared[1].'\\' : '';
        preg_match_all('/^\s*case\s+(\w+)\s*=/m', $source, $cases);

        /** @var class-string $class */
        $class = $namespace.$enum[1];

        $header = substr($source, 0, (int) strpos($source, $enum[0]));

        return new self($class, $cases[1], preg_match('/#\[[^\]]*\bPolicyOnly\b/', $header) === 1);
    }
}
