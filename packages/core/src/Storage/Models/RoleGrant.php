<?php

declare(strict_types=1);

namespace AzGuard\Storage\Models;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Kernel\Identity\RoleKey;
use AzGuard\Schema\Field;
use AzGuard\Storage\Internal\Concerns\BelongsToStorage;
use AzGuard\Storage\Internal\Concerns\GrantIdentity;
use AzGuard\Storage\Internal\Concerns\GuardsDirectWrites;
use Illuminate\Database\Eloquent\Model;

#[Spi(kind: SpiKind::Implement, since: '0.7')]
class RoleGrant extends Model
{
    use BelongsToStorage;
    use GrantIdentity;
    use GuardsDirectWrites;

    final public function roleKey(): RoleKey
    {
        return RoleKey::of($this->panel(), $this->identityString('role'));
    }

    /** @return list<Field> */
    public static function azguardFields(): array
    {
        return [];
    }

    /** @return array<string, string> */
    final protected function casts(): array
    {
        return [...$this->grantCasts(), 'role' => 'string'];
    }
}
