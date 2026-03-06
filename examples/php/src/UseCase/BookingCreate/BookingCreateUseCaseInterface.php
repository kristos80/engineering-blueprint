<?php

declare(strict_types=1);

namespace App\UseCase\BookingCreate;

interface BookingCreateUseCaseInterface
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(array $input): array;
}
