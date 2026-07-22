<?php
namespace App\Repositories;

interface UserRepositoryInterface { public function find(int $id): ?object; }

final class EloquentUserRepository implements UserRepositoryInterface
{
    public function find(int $id): ?object { return null; }
}
