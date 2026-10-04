<?php

declare(strict_types=1);

namespace AzGuard\Storage\Models;

use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Storage\Concerns\BelongsToStorage;
use AzGuard\Storage\Concerns\GuardsDirectWrites;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use BelongsToStorage;
    use GuardsDirectWrites;

    final public function permissionKey(): PermissionKey
    {
        return PermissionKey::of($this->panel(), $this->identityString('name'));
    }

    /** @return array<string, string> */
    final protected function casts(): array
    {
        return [...$this->storageCasts(), 'name' => 'string'];
    }
}
