<?php

declare(strict_types=1);

namespace AzGuard\Changes;

use AzGuard\Exceptions\Changes\InvalidChangeFieldsException;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * The complete replacement of the editable values of one grant: expiry and fields. Identity, role, scope and
 * origin are never part of it; the writer validates the values again under the panel lock.
 */
#[Api(since: '1.0')]
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
