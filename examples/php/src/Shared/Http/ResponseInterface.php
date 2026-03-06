<?php

declare(strict_types=1);

namespace App\Shared\Http;

interface ResponseInterface
{
    public function withStatus(int $code): static;

    /** @param array<string, mixed> $data */
    public function withJson(array $data): static;

    public function getStatusCode(): int;

    /** @return array<string, mixed> */
    public function getBody(): array;
}
