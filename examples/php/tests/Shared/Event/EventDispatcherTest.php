<?php

declare(strict_types=1);

namespace App\Tests\Shared\Event;

use App\Shared\Event\EventDispatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventDispatcherTest extends TestCase
{
    #[Test]
    public function dispatchWithNoListenersDoesNothing(): void
    {
        $dispatcher = new EventDispatcher();

        $dispatcher->dispatch('unknown.action', 'payload');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function listenerReceivesPayload(): void
    {
        $dispatcher = new EventDispatcher();
        $received = null;

        $dispatcher->onAction('booking.created', static function (mixed $payload) use (&$received): void {
            $received = $payload;
        });

        $dispatcher->dispatch('booking.created', 'test-payload');

        self::assertSame('test-payload', $received);
    }

    #[Test]
    public function multipleListenersAllFire(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = [];

        $dispatcher->onAction('booking.created', static function () use (&$calls): void {
            $calls[] = 'first';
        });

        $dispatcher->onAction('booking.created', static function () use (&$calls): void {
            $calls[] = 'second';
        });

        $dispatcher->dispatch('booking.created');

        self::assertCount(2, $calls);
    }

    #[Test]
    public function listenersFireInPriorityOrder(): void
    {
        $dispatcher = new EventDispatcher();
        $order = [];

        $dispatcher->onAction('booking.created', static function () use (&$order): void {
            $order[] = 'low';
        }, 10);

        $dispatcher->onAction('booking.created', static function () use (&$order): void {
            $order[] = 'high';
        }, 100);

        $dispatcher->onAction('booking.created', static function () use (&$order): void {
            $order[] = 'medium';
        }, 50);

        $dispatcher->dispatch('booking.created');

        self::assertSame(['high', 'medium', 'low'], $order);
    }

    #[Test]
    public function listenersForDifferentActionsDoNotInterfere(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = [];

        $dispatcher->onAction('booking.created', static function () use (&$calls): void {
            $calls[] = 'booking';
        });

        $dispatcher->onAction('payment.completed', static function () use (&$calls): void {
            $calls[] = 'payment';
        });

        $dispatcher->dispatch('booking.created');

        self::assertSame(['booking'], $calls);
    }
}
