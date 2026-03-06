<?php

declare(strict_types=1);

namespace App\Tests\Domain\ValueObject;

use App\Domain\ValueObject\BookingStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BookingStatusTest extends TestCase
{
    #[Test]
    public function pendingCanTransitionToConfirmed(): void
    {
        self::assertTrue(BookingStatus::Pending->canTransitionTo(BookingStatus::Confirmed));
    }

    #[Test]
    public function pendingCanTransitionToCancelled(): void
    {
        self::assertTrue(BookingStatus::Pending->canTransitionTo(BookingStatus::Cancelled));
    }

    #[Test]
    public function pendingCannotTransitionToCompleted(): void
    {
        self::assertFalse(BookingStatus::Pending->canTransitionTo(BookingStatus::Completed));
    }

    #[Test]
    public function confirmedCanTransitionToCompleted(): void
    {
        self::assertTrue(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Completed));
    }

    #[Test]
    public function confirmedCanTransitionToCancelled(): void
    {
        self::assertTrue(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Cancelled));
    }

    #[Test]
    public function confirmedCannotTransitionToPending(): void
    {
        self::assertFalse(BookingStatus::Confirmed->canTransitionTo(BookingStatus::Pending));
    }

    #[Test]
    public function cancelledCannotTransitionToAnything(): void
    {
        self::assertSame([], BookingStatus::Cancelled->allowedTransitions());
    }

    #[Test]
    public function completedCannotTransitionToAnything(): void
    {
        self::assertSame([], BookingStatus::Completed->allowedTransitions());
    }

    #[Test]
    public function pendingAllowedTransitions(): void
    {
        self::assertSame(
            [BookingStatus::Confirmed, BookingStatus::Cancelled],
            BookingStatus::Pending->allowedTransitions(),
        );
    }

    #[Test]
    public function confirmedAllowedTransitions(): void
    {
        self::assertSame(
            [BookingStatus::Completed, BookingStatus::Cancelled],
            BookingStatus::Confirmed->allowedTransitions(),
        );
    }
}
