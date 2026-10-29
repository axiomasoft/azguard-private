<?php

// ruleid: azguard.grant-table-outside-storage
use AzGuard\Storage\Internal\StorageRegistry;
// ok: azguard.grant-table-outside-storage
use AzGuard\Storage\Descriptor;

function grantTableOutsideStorage($query, string $name): void
{
    // ruleid: azguard.grant-table-outside-storage
    DB::table('azg_role_grants');

    // ruleid: azguard.grant-table-outside-storage
    $query->from('permission_grants');

    // ruleid: azguard.grant-table-outside-storage
    Schema::hasTable('azg_panel_state');

    // ruleid: azguard.grant-table-outside-storage
    $registry = new \AzGuard\Storage\Internal\StorageRegistry();

    // ok: azguard.grant-table-outside-storage
    DB::table('users');

    // ok: azguard.grant-table-outside-storage
    $resources = ['roles', 'role_grants'];

    // ok: azguard.grant-table-outside-storage
    $query->from($name);
}
