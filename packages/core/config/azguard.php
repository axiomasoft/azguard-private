<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Panels
    |--------------------------------------------------------------------------
    |
    | Panel providers of the application. A panel is a directory: its provider
    | describes the panel, and the permissions, policies and roles of the
    | panel live next to it. Providers of modules register themselves.
    |
    */

    'panels' => [
        'providers' => [
            // App\Guards\Admin\Panel::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults for every panel
    |--------------------------------------------------------------------------
    |
    | A panel takes a setting from its provider, then from its plugins, then
    | from AzGuard::configurePanels(), and only then from here. The command
    | azguard:panels:list --settings shows where each value came from.
    |
    */

    'defaults' => [

        // Prefix of permission names: true is the panel id (admin.orders.view),
        // false turns it off. A custom prefix is set on the panel.
        'resource_prefix' => true,

        'gate' => [
            // authoritative: the answer for an ability the panel owns is final.
            'mode' => 'authoritative',
        ],

        'cache' => [
            // A Laravel cache store; null keeps permission sets for the request only.
            'store' => null,
            'ttl' => 3600,
            'generation' => 1,
        ],

        'consistency' => [
            'reads' => 'primary',           // primary | default
            'state_refresh' => 'request',   // request | check
        ],

        // Dispatch a decision event on every check, for diagnostics.
        'trace_decisions' => false,

    ],

];
