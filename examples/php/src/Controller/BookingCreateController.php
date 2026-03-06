<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Exception\DomainException;
use App\Shared\Http\RequestInterface;
use App\Shared\Http\ResponseInterface;
use App\UseCase\BookingCreate\BookingCreateUseCaseInterface;

final class BookingCreateController extends AbstractController
{
    public function __construct(
        private readonly BookingCreateUseCaseInterface $useCase,
    ) {}

    protected function invoke(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->parsedBody();

        $errors = [];

        if (empty($body['phone'])) {
            $errors['phone'] = 'Phone number is required';
        }

        if (empty($body['name'])) {
            $errors['name'] = 'Name is required';
        }

        if (empty($body['service_id'])) {
            $errors['service_id'] = 'Service ID is required';
        }

        if (empty($body['datetime'])) {
            $errors['datetime'] = 'Datetime is required';
        }

        if ($errors !== []) {
            return $this->errorResponse($response, 422, 'VALIDATION_FAILED', 'Invalid input', $errors);
        }

        try {
            $result = $this->useCase->execute($body);

            return $this->jsonResponse($response, $result, 201);
        } catch (DomainException $e) {
            return $this->errorResponse($response, 409, 'CONFLICT', $e->getMessage());
        }
    }
}
