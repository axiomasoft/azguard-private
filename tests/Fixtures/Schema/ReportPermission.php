<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Schema;

use AzGuard\Permissions\Describe;
use AzGuard\Permissions\GrantedToAll;
use AzGuard\Permissions\RequiresGrant;

#[RequiresGrant]
enum ReportPermission: string
{
    #[GrantedToAll]
    #[Describe('Просмотр отчётов', group: 'Отчёты')]
    case View = 'reports.view';
    #[Describe('Выгрузка отчётов', group: 'Отчёты', description: 'CSV за период')]
    case Export = 'reports.export';
}
