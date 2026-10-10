<?php

declare(strict_types=1);

namespace AzGuard\Catalog;

use AzGuard\Exceptions\InvalidPermissionKeyException;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * One permission of a panel catalog as a source contributes it: the local name, who decides it and how it is shown.
 *
 * The panel is not part of the definition: the catalog that receives it gives the panel. Two definitions are equal
 * only when every field is equal.
 *
 * @spi
 */
final readonly class PermissionDefinition
{
    /**
     * @param  string  $local  local permission name such as `orders.view`
     * @param  BackedEnum|null  $case  enum case that names the permission in code
     * @param  class-string<Model>|null  $resourceModel  model the permission is about
     *
     * @throws InvalidPermissionKeyException when the local name breaks the grammar
     */
    public function __construct(
        public string $local,
        public PermissionAuthority $authority,
        public ?string $label = null,
        public ?string $group = null,
        public ?string $description = null,
        public ?BackedEnum $case = null,
        public ?string $resourceModel = null,
    ) {
        PermissionGrammar::assertLocalKey($local);
    }

    public function equals(self $other): bool
    {
        return $this->local === $other->local
            && $this->authority === $other->authority
            && $this->label === $other->label
            && $this->group === $other->group
            && $this->description === $other->description
            && $this->case === $other->case
            && $this->resourceModel === $other->resourceModel;
    }
}
