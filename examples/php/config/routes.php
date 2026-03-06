<?php

declare(strict_types=1);

/**
 * Route definitions.
 *
 * Maps HTTP method + path to controller class. In a real application,
 * this feeds your router (Slim, Symfony, Laravel, or similar).
 */

use App\Controller\BookingCreateController;

return [
    ['POST', '/api/bookings', BookingCreateController::class],
];
