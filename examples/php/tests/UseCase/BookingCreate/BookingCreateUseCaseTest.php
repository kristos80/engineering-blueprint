<?php

declare(strict_types=1);

namespace App\Tests\UseCase\BookingCreate;

use App\Domain\Entity\Booking;
use App\Domain\Entity\User;
use App\Domain\Exception\DomainException;
use App\Domain\Repository\BookingRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Shared\Event\EventDispatcherInterface;
use App\Shared\Transaction\TransactionInterface;
use App\UseCase\BookingCreate\BookingCreateUseCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BookingCreateUseCaseTest extends TestCase
{
    private TransactionInterface $transaction;
    private UserRepositoryInterface $userRepository;
    private BookingRepositoryInterface $bookingRepository;

    protected function setUp(): void
    {
        $this->transaction = $this->createStub(TransactionInterface::class);
        $this->transaction->method('run')->willReturnCallback(static fn(callable $op) => $op());

        $this->userRepository = $this->createStub(UserRepositoryInterface::class);
        $this->bookingRepository = $this->createStub(BookingRepositoryInterface::class);
    }

    #[Test]
    public function createsBookingSuccessfully(): void
    {
        $user = new User('user-1', '+30123456789', 'Sofia');
        $booking = new Booking('booking-1', 'user-1', 'service-1', new \DateTimeImmutable('2026-04-01 10:00:00'));

        $this->userRepository->method('upsert')->willReturn($user);
        $this->bookingRepository->method('hasBookingForSlot')->willReturn(false);
        $this->bookingRepository->method('create')->willReturn($booking);

        $dispatcher = $this->createStub(EventDispatcherInterface::class);

        $useCase = new BookingCreateUseCase(
            $this->transaction,
            $this->userRepository,
            $this->bookingRepository,
            $dispatcher,
        );

        $result = $useCase->execute([
            'phone' => '+30123456789',
            'name' => 'Sofia',
            'service_id' => 'service-1',
            'datetime' => '2026-04-01 10:00:00',
        ]);

        self::assertSame(['booking_id' => 'booking-1'], $result);
    }

    #[Test]
    public function throwsWhenSlotIsAlreadyBooked(): void
    {
        $this->bookingRepository->method('hasBookingForSlot')->willReturn(true);

        $dispatcher = $this->createStub(EventDispatcherInterface::class);

        $useCase = new BookingCreateUseCase(
            $this->transaction,
            $this->userRepository,
            $this->bookingRepository,
            $dispatcher,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('This time slot is already booked');

        $useCase->execute([
            'phone' => '+30123456789',
            'name' => 'Sofia',
            'service_id' => 'service-1',
            'datetime' => '2026-04-01 10:00:00',
        ]);
    }

    #[Test]
    public function dispatchesBookingCreatedEvent(): void
    {
        $user = new User('user-1', '+30123456789', 'Sofia');
        $booking = new Booking('booking-1', 'user-1', 'service-1', new \DateTimeImmutable('2026-04-01 10:00:00'));

        $this->userRepository->method('upsert')->willReturn($user);
        $this->bookingRepository->method('hasBookingForSlot')->willReturn(false);
        $this->bookingRepository->method('create')->willReturn($booking);

        // Mock — because the side effect IS the behavior we're testing
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with('booking.created', $booking);

        $useCase = new BookingCreateUseCase(
            $this->transaction,
            $this->userRepository,
            $this->bookingRepository,
            $dispatcher,
        );

        $useCase->execute([
            'phone' => '+30123456789',
            'name' => 'Sofia',
            'service_id' => 'service-1',
            'datetime' => '2026-04-01 10:00:00',
        ]);
    }
}
