<?php

declare(strict_types=1);

use AzGuard\Kernel\Grammar\PermissionGrammar;

it('classifies bounded seeded strings against an independent character grammar', function (): void {
    $validSegment = static function (string $segment): bool {
        if ($segment === '' || ! ctype_lower($segment[0]) && ! ctype_digit($segment[0])) {
            return false;
        }

        return strspn($segment, 'abcdefghijklmnopqrstuvwxyz0123456789_-') === strlen($segment);
    };
    $reference = static function (string $value, bool $pattern) use ($validSegment): bool {
        $parts = explode('.', $value);

        if (strlen($value) > 255 || count($parts) < 2) {
            return false;
        }
        foreach ($parts as $i => $part) {
            if (! $validSegment($part) && ! ($pattern && $i === count($parts) - 1 && in_array($part, ['*', '**'], true))) {
                return false;
            }
        }

        return true;
    };
    $alphabet = 'abc019_-.::*A()';
    for ($seed = 1; $seed <= 10; $seed++) {
        mt_srand($seed);
        for ($i = 0; $i < 10000; $i++) {
            $value = '';
            $length = mt_rand(0, 40);
            for ($j = 0; $j < $length; $j++) {
                $value .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }

            if (PermissionGrammar::isLocalKey($value) !== $reference($value, false) || PermissionGrammar::isPattern($value) !== $reference($value, true)) {
                test()->fail("grammar seed=$seed case=$i value=".json_encode($value));
            }
        }
    }
    $corpus = json_decode(file_get_contents(__DIR__.'/../../../Fixtures/Kernel/fuzz-corpus.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ([...$corpus['invalid_patterns'], 'a.'.str_repeat('b', 254), str_repeat('a.', 128).'b'] as $value) {
        expect(PermissionGrammar::isPattern($value))->toBeFalse();
    }
    expect(PermissionGrammar::isPattern('a.'.str_repeat('b', 253)))->toBeTrue()
        ->and(PermissionGrammar::isPanelId(str_repeat('a', 64)))->toBeTrue()
        ->and(PermissionGrammar::isPanelId(str_repeat('a', 65)))->toBeFalse()
        ->and(PermissionGrammar::isRoleKey(str_repeat('a', 65)))->toBeFalse();
});
