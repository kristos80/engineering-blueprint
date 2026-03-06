<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\Booking;
use App\Domain\Entity\User;
use App\Domain\Repository\BookingRepositoryInterface;
use App\Domain\ValueObject\BookingStatus;

final readonly class BookingRepository implements BookingRepositoryInterface
{
    public function __construct(
        private \PDO $connection,
    ) {}

    public function create(User $user, string $serviceId, \DateTimeImmutable $datetime): Booking
    {
        $id = bin2hex(random_bytes(16));

        $stmt = $this->connection->prepare(
            'INSERT INTO bookings (id, user_id, service_id, datetime, status)
             VALUES (:id, :user_id, :service_id, :datetime, :status)'
        );

        $stmt->execute([
            'id' => $id,
            'user_id' => $user->id,
            'service_id' => $serviceId,
            'datetime' => $datetime->format('Y-m-d H:i:s'),
            'status' => BookingStatus::Pending->value,
        ]);

        return new Booking($id, $user->id, $serviceId, $datetime);
    }

    public function findById(string $id): ?Booking
    {
        $stmt = $this->connection->prepare(
            'SELECT id, user_id, service_id, datetime, status FROM bookings WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new Booking(
            $row['id'],
            $row['user_id'],
            $row['service_id'],
            new \DateTimeImmutable($row['datetime']),
            BookingStatus::from($row['status']),
        );
    }

    public function hasBookingForSlot(string $serviceId, \DateTimeImmutable $datetime): bool
    {
        $stmt = $this->connection->prepare(
            'SELECT COUNT(*) FROM bookings
             WHERE service_id = :service_id AND datetime = :datetime AND status != :cancelled'
        );

        $stmt->execute([
            'service_id' => $serviceId,
            'datetime' => $datetime->format('Y-m-d H:i:s'),
            'cancelled' => BookingStatus::Cancelled->value,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
