<?php

declare(strict_types=1);

namespace AzGuard\Filament\Internal\Console;

use ParseError;

/**
 * @internal Puts attributes on the permission enum the core generator wrote, as text: the file is not loaded and its
 * `use` lines stay in the order Pint keeps.
 */
final class EnumMarks
{
    /**
     * Every class is imported once under a name nothing else in the file has: the short name when it is free, otherwise
     * the short name behind the last segment of its namespace, then a number.
     *
     * @param  list<array{attribute: class-string, class: class-string}>  $marks
     *
     * @throws ParseError when the result is not valid PHP; the caller keeps the file it has
     */
    public static function add(string $source, array $marks): string
    {
        $taken = [];
        $uses = [];

        if (preg_match('/^enum\s+(\w+)\s*:/m', $source, $enum) === 1) {
            $taken[strtolower($enum[1])] = $enum[1];
        }

        if (preg_match_all('/^use ([\w\\\\]+)(?: as (\w+))?;$/m', $source, $existing, PREG_SET_ORDER) > 0) {
            foreach ($existing as $use) {
                $name = $use[2] ?? class_basename($use[1]);
                $taken[strtolower($name)] = $use[1];
                $uses[$use[1]] = $name === class_basename($use[1]) ? $use[1] : $use[1].' as '.$name;
            }
        }
        $lines = [];

        foreach ($marks as ['attribute' => $attribute, 'class' => $class]) {
            $lines[] = '#['.self::import($attribute, $taken, $uses).'('.self::import($class, $taken, $uses).'::class)]';
        }
        $uses = array_values($uses);
        usort($uses, static fn (string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));
        $block = implode("\n", array_map(static fn (string $use): string => 'use '.$use.';', $uses));

        $source = (string) preg_replace('/^(?:use [\w\\\\]+(?: as \w+)?;\n)+/m', $block."\n", $source, 1);
        $source = (string) preg_replace('/^enum /m', implode("\n", $lines)."\nenum ", $source, 1);

        // TOKEN_PARSE throws a ParseError for a source PHP would not parse.
        if (token_get_all($source, TOKEN_PARSE) === []) {
            throw new ParseError('The permission enum is empty.');
        }

        return $source;
    }

    /**
     * @param  array<string, string>  $taken  lower-case name => the class it stands for
     * @param  array<string, string>  $uses  class => its `use` line
     */
    private static function import(string $class, array &$taken, array &$uses): string
    {
        if (isset($uses[$class])) {
            return str_contains($uses[$class], ' as ') ? substr($uses[$class], strrpos($uses[$class], ' ') + 1) : class_basename($class);
        }
        $short = class_basename($class);
        $segments = explode('\\', $class);
        $parent = $segments[count($segments) - 2] ?? '';
        $name = $short;

        for ($number = 2; isset($taken[strtolower($name)]); $number++) {
            $name = ($number === 2 ? $parent.$short : $parent.$short.$number);
        }
        $taken[strtolower($name)] = $class;
        $uses[$class] = $name === $short ? $class : $class.' as '.$name;

        return $name;
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
