<?php

declare(strict_types=1);

namespace App\Tests\Domain\Entity;

use App\Domain\Entity\Booking;
use App\Domain\Exception\DomainException;
use App\Domain\ValueObject\BookingStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BookingTest extends TestCase
{
    #[Test]
    public function newBookingHasPendingStatus(): void
    {
        $booking = $this->createBooking();

        self::assertSame(BookingStatus::Pending, $booking->status());
    }

    #[Test]
    public function confirmTransitionsToPendingToConfirmed(): void
    {
        $booking = $this->createBooking();

        $booking->confirm();

        self::assertSame(BookingStatus::Confirmed, $booking->status());
    }

    #[Test]
    public function cancelFromPendingTransitionsToCancelled(): void
    {
        $booking = $this->createBooking();

        $booking->cancel();

        self::assertSame(BookingStatus::Cancelled, $booking->status());
    }

    #[Test]
    public function cancelFromConfirmedTransitionsToCancelled(): void
    {
        $booking = $this->createBooking();
        $booking->confirm();

        $booking->cancel();

        self::assertSame(BookingStatus::Cancelled, $booking->status());
    }

    #[Test]
    public function completeFromConfirmedTransitionsToCompleted(): void
    {
        $booking = $this->createBooking();
        $booking->confirm();

        $booking->complete();

        self::assertSame(BookingStatus::Completed, $booking->status());
    }

    #[Test]
    public function confirmFromConfirmedThrowsDomainException(): void
    {
        $booking = $this->createBooking();
        $booking->confirm();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot transition booking from confirmed to confirmed');

        $booking->confirm();
    }

    #[Test]
    public function completeFromPendingThrowsDomainException(): void
    {
        $booking = $this->createBooking();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot transition booking from pending to completed');

        $booking->complete();
    }

    #[Test]
    public function confirmFromCancelledThrowsDomainException(): void
    {
        $booking = $this->createBooking();
        $booking->cancel();

        $this->expectException(DomainException::class);

        $booking->confirm();
    }

    #[Test]
    public function confirmFromCompletedThrowsDomainException(): void
    {
        $booking = $this->createBooking();
        $booking->confirm();
        $booking->complete();

        $this->expectException(DomainException::class);

        $booking->confirm();
    }

    #[Test]
    public function completeFromCancelledThrowsDomainException(): void
    {
        $booking = $this->createBooking();
        $booking->cancel();

        $this->expectException(DomainException::class);

        $booking->complete();
    }

    private function createBooking(): Booking
    {
        return new Booking(
            'booking-1',
            'user-1',
            'service-1',
            new \DateTimeImmutable('2026-04-01 10:00:00'),
        );
    }
}
