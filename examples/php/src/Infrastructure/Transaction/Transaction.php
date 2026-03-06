<?php

declare(strict_types=1);

namespace App\Infrastructure\Transaction;

use App\Shared\Transaction\TransactionInterface;

final readonly class Transaction implements TransactionInterface
{
    public function __construct(
        private \PDO $connection,
    ) {}

    public function run(callable $operation): mixed
    {
        $this->connection->beginTransaction();

        try {
            $result = $operation();
            $this->connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }
}
