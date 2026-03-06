<?php

declare(strict_types=1);

namespace App\Tests\TestDouble;

use App\Shared\Http\ResponseInterface;

final class StubResponse implements ResponseInterface
{
    private int $statusCode = 200;

    /** @var array<string, mixed> */
    private array $body = [];

    public function withStatus(int $code): static
    {
        $clone = clone $this;
        $clone->statusCode = $code;

        return $clone;
    }

    public function withJson(array $data): static
    {
        $clone = clone $this;
        $clone->body = $data;

        return $clone;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): array
    {
        return $this->body;
    }
}
