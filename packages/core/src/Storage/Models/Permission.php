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

    /** A display column (label, group, description); null when it is empty or not text. */
    final public function displayText(string $column): ?string
    {
        $value = $this->getAttribute($column);

        return is_string($value) ? $value : null;
    }

    /** @return array<string, string> */
    final protected function casts(): array
    {
        return [...$this->storageCasts(), 'name' => 'string'];
    }
}
