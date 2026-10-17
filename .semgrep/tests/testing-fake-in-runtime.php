<?php

// ruleid: azguard.testing-fake-in-runtime
use AzGuard\Testing\AzGuardFake;
// ok: azguard.testing-fake-in-runtime
use AzGuard\Contracts\Decider;

function testingFakeInRuntime(): void
{
    // ruleid: azguard.testing-fake-in-runtime
    $fake = new AzGuard\Testing\AzGuardFake();

    // ruleid: azguard.testing-fake-in-runtime
    \AzGuard\Testing\AzGuardFake::boot();

    // ok: azguard.testing-fake-in-runtime
    $decider = new Decider();
}
