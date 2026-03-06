<?php

declare(strict_types=1);

/**
 * Dependency Injection container bindings.
 *
 * Interface -> Implementation mappings. In a real application, this feeds
 * your DI container (PHP-DI, Symfony DI, or similar). Each use case
 * interface is explicitly bound to its implementation.
 */

use App\Domain\Repository\BookingRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Infrastructure\Repository\BookingRepository;
use App\Infrastructure\Repository\UserRepository;
use App\Infrastructure\Transaction\Transaction;
use App\Shared\Event\EventDispatcher;
use App\Shared\Event\EventDispatcherInterface;
use App\Shared\Transaction\TransactionInterface;
use App\UseCase\BookingCreate\BookingCreateUseCase;
use App\UseCase\BookingCreate\BookingCreateUseCaseInterface;

return [
    // Transaction
    TransactionInterface::class => Transaction::class,

    // Repositories
    UserRepositoryInterface::class => UserRepository::class,
    BookingRepositoryInterface::class => BookingRepository::class,

    // Events
    EventDispatcherInterface::class => EventDispatcher::class,

    // Use Cases
    BookingCreateUseCaseInterface::class => BookingCreateUseCase::class,
];
