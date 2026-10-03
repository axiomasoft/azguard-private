<?php

declare(strict_types=1);

use AzGuard\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature', 'Regression', 'Engines');

uses()->beforeEach(function (): void {
    if (! in_array(env('DB_CONNECTION', 'sqlite'), ['pgsql', 'mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Engine suite requires PostgreSQL, MySQL or MariaDB.');
    }
})->in('Engines');
