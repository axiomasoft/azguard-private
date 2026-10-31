<?php

function unserializeCases(string $stored): void
{
    // ruleid: azguard.unserialize
    unserialize($stored);

    // ruleid: azguard.unserialize
    unserialize($stored, ['allowed_classes' => true]);

    // ruleid: azguard.unserialize
    @unserialize($stored);

    // ok: azguard.unserialize
    unserialize($stored, ['allowed_classes' => false]);

    // ok: azguard.unserialize
    @unserialize($stored, ['allowed_classes' => [Foo::class, Bar::class]]);
}
