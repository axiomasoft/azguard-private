<?php

declare(strict_types=1);

// Development-only patch for pestphp/pest-plugin-mutate 4.0.1 (pestphp/pest#1771).
// Fail closed on upstream changes; remove this patch after adopting a verified upstream fix.
$file = __DIR__.'/../vendor/pestphp/pest-plugin-mutate/src/MutationTest.php';
$originalHash = 'bc4883811c45ab9549bcb07d004b46163ef35e59c78b3deac714744962de03b5';
$source = file_get_contents($file);

if ($source === false) {
    throw new RuntimeException('Cannot read the installed mutation runner.');
}

$replacements = [
    'use ParaTest\\Options;' => "use AzGuard\\Tests\\Support\\MutationFilter;\nuse ParaTest\\Options;",
    "'--filter=\"'.implode('|', \$filters).'\"'," => 'MutationFilter::argument(array_values($filters)),',
    '        if ($this->process->isSuccessful()) {' => <<<'PHP'
        MutationFilter::assertTestsRan($this->process->getOutput().$this->process->getErrorOutput());

        if ($this->process->isSuccessful()) {
PHP,
];

$unpatched = str_replace(array_values($replacements), array_keys($replacements), $source);

if (hash('sha256', $unpatched) !== $originalHash) {
    throw new RuntimeException('Mutation runner source changed; review the compatibility patch before running this gate.');
}

$patched = str_replace(array_keys($replacements), array_values($replacements), $unpatched, $count);

if ($count !== count($replacements)) {
    throw new RuntimeException('Mutation runner patch did not match every required location.');
}

if ($source !== $patched && file_put_contents($file, $patched) !== strlen($patched)) {
    throw new RuntimeException('Could not apply the mutation runner compatibility patch.');
}
