<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\User;
use App\Domain\Repository\UserRepositoryInterface;

final readonly class UserRepository implements UserRepositoryInterface
{
    public function __construct(
        private \PDO $connection,
    ) {}

    public function upsert(string $phone, string $name): User
    {
        $id = bin2hex(random_bytes(16));

        $stmt = $this->connection->prepare(
            'INSERT INTO users (id, phone, name) VALUES (:id, :phone, :name)
             ON DUPLICATE KEY UPDATE name = :name_update'
        );

        $stmt->execute([
            'id' => $id,
            'phone' => $phone,
            'name' => $name,
            'name_update' => $name,
        ]);

        return $this->findByPhone($phone);
    }

    public function findById(string $id): ?User
    {
        $stmt = $this->connection->prepare('SELECT id, phone, name FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new User($row['id'], $row['phone'], $row['name']);
    }

    private function findByPhone(string $phone): User
    {
        $stmt = $this->connection->prepare('SELECT id, phone, name FROM users WHERE phone = :phone');
        $stmt->execute(['phone' => $phone]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return new User($row['id'], $row['phone'], $row['name']);
    }
}
