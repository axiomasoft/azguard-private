<?php

declare(strict_types=1);

namespace AzGuard\Storage\Models;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Schema\Field;
use AzGuard\Storage\Internal\Concerns\BelongsToStorage;
use AzGuard\Storage\Internal\Concerns\GrantIdentity;
use AzGuard\Storage\Internal\Concerns\GuardsDirectWrites;
use Illuminate\Database\Eloquent\Model;

#[Spi(kind: SpiKind::Implement, since: '0.7')]
class PermissionGrant extends Model
{
    use BelongsToStorage;
    use GrantIdentity;
    use GuardsDirectWrites;

    final public function permissionKey(): PermissionKey|PermissionPattern
    {
        $name = $this->identityString('permission');

        return str_contains($name, '*') ? PermissionPattern::of($this->panel(), $name) : PermissionKey::of($this->panel(), $name);
    }

    /** @return list<Field> */
    public static function azguardFields(): array
    {
        return [];
    }

    /** @return array<string, string> */
    final protected function casts(): array
    {
        return [...$this->grantCasts(), 'permission' => 'string'];
    }
}
