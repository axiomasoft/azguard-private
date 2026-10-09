<?php

declare(strict_types=1);

namespace AzGuard\Filament\Commands;

/**
 * @internal Puts attributes on the permission enum the core generator wrote, as text: the file is not loaded and its
 * `use` lines stay in the order Pint keeps.
 */
final class EnumMarks
{
    /**
     * @param  list<array{attribute: class-string, class: class-string}>  $marks
     */
    public static function add(string $source, array $marks): string
    {
        $uses = [];
        $lines = [];

        foreach ($marks as ['attribute' => $attribute, 'class' => $class]) {
            $uses[] = $attribute;
            $uses[] = $class;
            $lines[] = '#['.class_basename($attribute).'('.class_basename($class).'::class)]';
        }

        if (preg_match_all('/^use ([\w\\\\]+);$/m', $source, $existing) > 0) {
            $uses = [...$existing[1], ...$uses];
        }
        $uses = array_values(array_unique($uses));
        usort($uses, static fn (string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));
        $block = implode("\n", array_map(static fn (string $use): string => 'use '.$use.';', $uses));

        $source = (string) preg_replace('/^(?:use [\w\\\\]+;\n)+/m', $block."\n", $source, 1);

        return (string) preg_replace('/^enum /m', implode("\n", $lines)."\nenum ", $source, 1);
    }

    /** @return class-string|null */
    public static function classOf(string $source): ?string
    {
        if (preg_match('/^enum\s+(\w+)\s*:/m', $source, $enum) !== 1) {
            return null;
        }
        $namespace = preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $source, $declared) === 1 ? $declared[1].'\\' : '';

        /** @var class-string */
        return $namespace.$enum[1];
    }
}
