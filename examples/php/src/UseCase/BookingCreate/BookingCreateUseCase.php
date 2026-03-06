<?php

declare(strict_types=1);

namespace App\UseCase\BookingCreate;

use App\Domain\Exception\DomainException;
use App\Domain\Repository\BookingRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Shared\Event\EventDispatcherInterface;
use App\Shared\Transaction\TransactionInterface;

final readonly class BookingCreateUseCase implements BookingCreateUseCaseInterface
{
    public function __construct(
        private TransactionInterface $transaction,
        private UserRepositoryInterface $userRepository,
        private BookingRepositoryInterface $bookingRepository,
        private EventDispatcherInterface $dispatcher,
    ) {}

    /** @inheritDoc */
    public function execute(array $input): array
    {
        return $this->transaction->run(function () use ($input): array {
            $datetime = new \DateTimeImmutable($input['datetime']);

            if ($this->bookingRepository->hasBookingForSlot($input['service_id'], $datetime)) {
                throw new DomainException('This time slot is already booked');
            }

            $user = $this->userRepository->upsert($input['phone'], $input['name']);
            $booking = $this->bookingRepository->create($user, $input['service_id'], $datetime);

            $this->dispatcher->dispatch('booking.created', $booking);

            return ['booking_id' => $booking->id];
        });
    }
}
