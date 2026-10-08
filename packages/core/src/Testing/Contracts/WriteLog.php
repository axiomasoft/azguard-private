<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

/**
 * @internal The statements that change data which a suite saw while it watched.
 */
final class WriteLog
{
    public bool $active = true;

    /** @var list<string> */
    public array $writes = [];

    public static function isWrite(string $sql): bool
    {
        // Ignore comments and quoted values/identifiers, then recognize ordinary and CTE writes.
        $sql = preg_replace_callback(<<<'REGEX'
            ~ /\*(?!\!).*?\*/ | --[^\r\n]* | \#[^\r\n]* |
              '(?:''|\\.|[^'\\])*' | "(?:""|\\.|[^"\\])*" | `(?:``|[^`])*` |
              (\$[A-Za-z_][A-Za-z0-9_]*\$|\$\$).*?\1
            ~sx
            REGEX, static fn (array $match): string => preg_match("/\A'(true|false|on|off|1|0)'\z/i", $match[0], $boolean) === 1
                ? ' '.$boolean[1].' ' : ' ', $sql) ?? $sql;
        $sql = preg_replace('/\A\s*\/\*!\d*\s*/', '', $sql) ?? $sql;
        $sql = preg_replace('/\bfor\s+(?:no\s+key\s+)?update\b/i', ' ', $sql) ?? $sql;

        foreach (explode(';', $sql) as $statement) {
            if (preg_match('/\A\s*explain\b\s*(?:\(([^)]*)\)|(?:(analyze)\s+)?(?:verbose\s+)?)(.*)\z/is', $statement, $explain) === 1) {
                $analyze = $explain[2] !== '';

                if ($explain[1] !== '') {
                    $analyze = preg_match('/\banalyze(?:\s+(true|false|on|off|1|0))?(?=\s*(?:,|\z))/i', $explain[1], $option) === 1
                        && ! in_array(strtolower($option[1] ?? 'true'), ['false', 'off', '0'], true);
                }

                if (! $analyze) {
                    continue;
                }
                $statement = $explain[3];
            }

            if (preg_match('/\A\s*(?:insert|update|delete|replace|create|drop|alter|truncate|merge)\b|\A\s*with\b[\s\S]*\b(?:insert|update|delete|replace|merge)\b/i', $statement) === 1) {
                return true;
            }
        }

        return false;
    }
}
