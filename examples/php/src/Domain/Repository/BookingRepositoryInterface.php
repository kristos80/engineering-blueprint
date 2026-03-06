<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Booking;
use App\Domain\Entity\User;

interface BookingRepositoryInterface
{
    public function create(User $user, string $serviceId, \DateTimeImmutable $datetime): Booking;

    public function findById(string $id): ?Booking;

    public function hasBookingForSlot(string $serviceId, \DateTimeImmutable $datetime): bool;
}
