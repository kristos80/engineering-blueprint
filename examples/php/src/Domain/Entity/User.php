<?php

declare(strict_types=1);

namespace App\Domain\Entity;

final readonly class User
{
    public function __construct(
        public string $id,
        public string $phone,
        public string $name,
    ) {}
}
