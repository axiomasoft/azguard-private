<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Exceptions\InvalidChangeFieldsException;
use DateTimeImmutable;

/**
 * The complete replacement of the editable values of one grant: expiry and fields. Identity, role, scope and
 * origin are never part of it; the writer validates the values again under the panel lock.
 *
 * @api
 */
final readonly class GrantDetails
{
    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws InvalidChangeFieldsException when fields are not keyed by name
     */
    public function __construct(public ?DateTimeImmutable $until = null, public array $fields = [])
    {
        Change::assertFieldShape($fields);
    }
}
