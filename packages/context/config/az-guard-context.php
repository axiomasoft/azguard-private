<?php

use AzGuard\Context\Strategies\GlobalPlusContextStrategy;

return [
    /*
    |--------------------------------------------------------------------------
    | Merge Strategy
    |--------------------------------------------------------------------------
    |
    | Defines how global permissions and context permissions are merged.
    |
    | Built-in strategies:
    |   GlobalPlusContextStrategy  — global ∪ context (default)
    |   ContextOnlyStrategy        — context only, global ignored
    |   DenyWithoutContextStrategy — empty set without a context
    |
    | You may provide your own class implementing MergeStrategy.
    |
    */
    'merge_strategy' => GlobalPlusContextStrategy::class,

    /*
    |--------------------------------------------------------------------------
    | Context Resolvers
    |--------------------------------------------------------------------------
    |
    | List of FQCNs of classes implementing ResolvesContext.
    | Each resolver extracts an AuthorizationContext from the Request
    | and sets it on the AuthorizationContextManager.
    |
    | Example:
    |   'resolvers' => [
    |       App\AzGuard\WorkspaceContextResolver::class,
    |   ],
    |
    */
    'resolvers' => [],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Override if the default table name conflicts with an existing table.
    |
    */
    'table_names' => [
        'context_roles' => 'az_guard_context_roles',
    ],
];
