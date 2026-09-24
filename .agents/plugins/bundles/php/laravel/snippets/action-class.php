<?php
namespace App\Actions;

final class CreateUserAction
{
    public function execute(array $data): object { return (object) $data; }
}
