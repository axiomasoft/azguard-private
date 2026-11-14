<?php

declare(strict_types=1);

use AzGuard\Storage\Schema\IdentityColumns;
use AzGuard\Storage\StorageDescriptor;

it('rejects non-identifier scope constraint inputs before running SQL', function (string $table, string $short, string $scope): void {
    $storage = new StorageDescriptor('default', 'testbench', 'azg_', 'string');
    expect(fn () => IdentityColumns::scopeConstraint($storage, $table, $short, $scope))->toThrow(InvalidArgumentException::class);
})->with([
    ['audit_log; DROP TABLE users', 'al', 'tenant'],
    ['audit_log', "al'); END; --", 'tenant'],
    ['audit_log', 'al', 'tenant) OR 1=1 --'],
    ['audit_log', 'al', ''],
]);
