<?php

declare(strict_types=1);

namespace App\Tests\TestDouble;

use App\Shared\Http\RequestInterface;

final class StubRequest implements RequestInterface
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private readonly array $body = [],
        private readonly array $attributes = [],
    ) {}

    public function parsedBody(): array
    {
        return $this->body;
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
