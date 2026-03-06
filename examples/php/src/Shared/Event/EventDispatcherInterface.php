<?php

declare(strict_types=1);

namespace App\Shared\Event;

interface EventDispatcherInterface
{
    public function onAction(string $action, callable $listener, int $priority = 0): void;

    public function dispatch(string $action, mixed $payload = null): void;
}
