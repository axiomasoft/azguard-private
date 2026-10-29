<?php

function dynamicCode(string $input, object $actor): void
{
    // ruleid: azguard.dynamic-code
    eval($input);

    // ruleid: azguard.dynamic-code
    assert('is_string($x)');

    // ruleid: azguard.dynamic-code
    assert('$'.$input);

    // ruleid: azguard.dynamic-code
    exec($input);

    // ruleid: azguard.dynamic-code
    shell_exec($input);

    // ruleid: azguard.dynamic-code
    system($input);

    // ruleid: azguard.dynamic-code
    passthru($input);

    // ruleid: azguard.dynamic-code
    proc_open($input, [], $pipes);

    // ruleid: azguard.dynamic-code
    popen($input, 'r');

    // ok: azguard.dynamic-code
    assert($actor instanceof Countable);

    // ok: azguard.dynamic-code
    ActorRef::system('console');

    // ok: azguard.dynamic-code
    $actor->exec($input);
}
