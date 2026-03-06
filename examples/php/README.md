# PHP Reference Implementation

A concrete PHP implementation of the [engineering blueprint](../../README.md). Demonstrates architecture layers, dependency injection, transactions, validation, error handling, events, state guards, and testing patterns through a booking system example.

## Install & Run Tests

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpunit --coverage-text   # requires Xdebug or PCOV
```

## What's Covered

| Blueprint Section | Example |
|---|---|
| Project Structure | `src/` layout mirrors the blueprint exactly |
| Layered Architecture | Controller -> UseCase -> Repository/Domain |
| Dependency Injection | `config/container.php` with interface -> implementation bindings |
| Transaction Boundaries | `BookingCreateUseCase` wraps operations in `TransactionInterface::run()` |
| Input Validation | `BookingCreateController` validates before calling use case |
| Error Handling | Exception-to-HTTP mapping (422, 409) with JSON envelope |
| Response Format | `data` on success, `error` on failure — never both |
| Event System | `EventDispatcher` with named actions and priority |
| State Guards | `Booking` entity enforces valid status transitions |
| Stubs vs Mocks | Use case tests use stubs; mock only for event dispatch verification |
| Isolation Per Layer | Controller tests stub use cases; use case tests stub repositories |

## Infrastructure

The `src/Infrastructure/` directory contains repository and transaction implementations with raw SQL (no ORM). These are excluded from unit test coverage — they require integration tests against a real database.
