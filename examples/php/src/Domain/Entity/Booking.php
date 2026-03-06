<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Exception\DomainException;
use App\Domain\ValueObject\BookingStatus;

final class Booking
{
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $serviceId,
        public readonly \DateTimeImmutable $datetime,
        private BookingStatus $status = BookingStatus::Pending,
    ) {}

    public function status(): BookingStatus
    {
        return $this->status;
    }

    public function confirm(): void
    {
        $this->transitionTo(BookingStatus::Confirmed);
    }

    public function cancel(): void
    {
        $this->transitionTo(BookingStatus::Cancelled);
    }

    public function complete(): void
    {
        $this->transitionTo(BookingStatus::Completed);
    }

    private function transitionTo(BookingStatus $target): void
    {
        if (!$this->status->canTransitionTo($target)) {
            throw new DomainException(
                sprintf('Cannot transition booking from %s to %s', $this->status->value, $target->value)
            );
        }

        $this->status = $target;
    }
}
