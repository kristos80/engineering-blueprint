<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class ValidationException extends \RuntimeException
{
    /** @param array<string, string> $fields */
    public function __construct(
        string $message,
        private readonly array $fields = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }
}
