<?php

declare(strict_types=1);

namespace AzGuard\Exceptions;

final class InvalidChangeFieldsException extends ChangeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Invalid grant fields: '.json_encode($errors, JSON_THROW_ON_ERROR));
    }

    public function code(): string
    {
        return 'invalid_change_fields';
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
