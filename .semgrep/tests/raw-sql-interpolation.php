<?php

function rawSqlInterpolation($query, string $column, string $value): void
{
    // ruleid: azguard.raw-sql-interpolation
    $query->whereRaw("lower($column) = 'x'");

    // ruleid: azguard.raw-sql-interpolation
    $query->selectRaw($column.' as name, count(*) as aggregate');

    // ruleid: azguard.raw-sql-interpolation
    $query->orderByRaw("{$column} desc");

    // ruleid: azguard.raw-sql-interpolation
    $query->havingRaw('count(*) > '.$value);

    // ruleid: azguard.raw-sql-interpolation
    DB::raw("coalesce($column, 0)");

    // ok: azguard.raw-sql-interpolation
    $query->whereRaw('lower(name) = ?', [$value]);

    // ok: azguard.raw-sql-interpolation
    $query->whereRaw('0 = 1');

    // ok: azguard.raw-sql-interpolation
    $query->selectRaw('count(*) as aggregate');

    // ok: azguard.raw-sql-interpolation
    DB::raw('now()');
}
