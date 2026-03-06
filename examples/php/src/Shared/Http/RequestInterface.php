<?php

declare(strict_types=1);

namespace App\Shared\Http;

interface RequestInterface
{
    /** @return array<string, mixed> */
    public function parsedBody(): array;

    public function getAttribute(string $name): mixed;
}
