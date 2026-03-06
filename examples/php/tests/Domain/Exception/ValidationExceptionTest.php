<?php

declare(strict_types=1);

namespace App\Tests\Domain\Exception;

use App\Domain\Exception\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidationExceptionTest extends TestCase
{
    #[Test]
    public function carriesMessageAndFields(): void
    {
        $fields = ['phone' => 'Phone is required', 'name' => 'Name is required'];
        $exception = new ValidationException('Validation failed', $fields);

        self::assertSame('Validation failed', $exception->getMessage());
        self::assertSame($fields, $exception->fields());
    }

    #[Test]
    public function defaultsToEmptyFields(): void
    {
        $exception = new ValidationException('Validation failed');

        self::assertSame([], $exception->fields());
    }
}
