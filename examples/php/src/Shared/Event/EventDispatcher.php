<?php

declare(strict_types=1);

namespace App\Shared\Event;

final class EventDispatcher implements EventDispatcherInterface
{
    /** @var array<string, list<array{callable, int}>> */
    private array $listeners = [];

    public function onAction(string $action, callable $listener, int $priority = 0): void
    {
        $this->listeners[$action][] = [$listener, $priority];
    }

    public function dispatch(string $action, mixed $payload = null): void
    {
        $listeners = $this->listeners[$action] ?? [];
        usort($listeners, static fn(array $a, array $b): int => $b[1] <=> $a[1]);

        foreach ($listeners as [$listener]) {
            $listener($payload);
        }
    }
}
