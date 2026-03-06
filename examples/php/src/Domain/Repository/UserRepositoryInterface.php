<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\User;

interface UserRepositoryInterface
{
    public function upsert(string $phone, string $name): User;

    public function findById(string $id): ?User;
}
