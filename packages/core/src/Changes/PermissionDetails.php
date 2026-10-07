<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Exceptions\InvalidChangeFieldsException;

/**
 * The complete replacement of the editable values of one dynamic permission: label, group, description and fields.
 * Name, tenant and the Grants authority mode are never part of it; the writer validates the values again under the
 * panel lock. A dynamic permission declares no field schema, so any field is refused when the change is validated.
 *
 * @api
 */
final readonly class PermissionDetails
{
    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws InvalidChangeFieldsException when fields are not keyed by name
     */
    public function __construct(
        public ?string $label = null,
        public ?string $group = null,
        public ?string $description = null,
        public array $fields = [],
    ) {
        Change::assertFieldShape($fields);
    }
}
