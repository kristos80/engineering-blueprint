<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\BookingCreateController;
use App\Domain\Exception\DomainException;
use App\Tests\TestDouble\StubRequest;
use App\Tests\TestDouble\StubResponse;
use App\UseCase\BookingCreate\BookingCreateUseCaseInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BookingCreateControllerTest extends TestCase
{
    #[Test]
    public function returnsValidationErrorWhenBodyIsEmpty(): void
    {
        $useCase = $this->createStub(BookingCreateUseCaseInterface::class);
        $controller = new BookingCreateController($useCase);

        $response = $controller(new StubRequest(), new StubResponse());

        self::assertSame(422, $response->getStatusCode());

        $body = $response->getBody();
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
        self::assertArrayHasKey('phone', $body['error']['fields']);
        self::assertArrayHasKey('name', $body['error']['fields']);
        self::assertArrayHasKey('service_id', $body['error']['fields']);
        self::assertArrayHasKey('datetime', $body['error']['fields']);
    }

    #[Test]
    public function returnsValidationErrorForSingleMissingField(): void
    {
        $useCase = $this->createStub(BookingCreateUseCaseInterface::class);
        $controller = new BookingCreateController($useCase);

        $request = new StubRequest([
            'name' => 'Sofia',
            'service_id' => 'service-1',
            'datetime' => '2026-04-01 10:00:00',
        ]);

        $response = $controller($request, new StubResponse());

        self::assertSame(422, $response->getStatusCode());

        $fields = $response->getBody()['error']['fields'];
        self::assertArrayHasKey('phone', $fields);
        self::assertCount(1, $fields);
    }

    #[Test]
    public function returnsCreatedOnSuccess(): void
    {
        $useCase = $this->createStub(BookingCreateUseCaseInterface::class);
        $useCase->method('execute')->willReturn(['booking_id' => 'abc-123']);

        $controller = new BookingCreateController($useCase);

        $request = new StubRequest([
            'phone' => '+30123456789',
            'name' => 'Sofia',
            'service_id' => 'service-1',
            'datetime' => '2026-04-01 10:00:00',
        ]);

        $response = $controller($request, new StubResponse());

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['data' => ['booking_id' => 'abc-123']], $response->getBody());
    }

    #[Test]
    public function returnsConflictOnDomainException(): void
    {
        $useCase = $this->createStub(BookingCreateUseCaseInterface::class);
        $useCase->method('execute')->willThrowException(
            new DomainException('This time slot is already booked'),
        );

        $controller = new BookingCreateController($useCase);

        $request = new StubRequest([
            'phone' => '+30123456789',
            'name' => 'Sofia',
            'service_id' => 'service-1',
            'datetime' => '2026-04-01 10:00:00',
        ]);

        $response = $controller($request, new StubResponse());

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT', $response->getBody()['error']['code']);
        self::assertSame('This time slot is already booked', $response->getBody()['error']['message']);
    }
}
