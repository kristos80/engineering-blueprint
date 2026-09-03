# Engineering Blueprint

This repository is a standalone engineering blueprint — a language-agnostic, framework-agnostic reference for building applications on object-oriented, stateless request-response architecture.

## How to use this in other projects

Reference this blueprint from any project's `CLAUDE.md`:

```
## Engineering Standards
Follow the engineering blueprint at: /path/to/engineering-blueprint/README.md
```

## Standards summary

When working on any project that references this blueprint, follow these rules:

### Architecture
- Layered architecture: Controller → UseCase → Repository/Domain
- Controllers handle HTTP only — zero business logic
- One use case = one business operation = one transaction boundary
- Use cases do NOT call other use cases — extract shared logic into repository methods or domain services
- Anemic entities — data + constructor invariants only, no mutating methods. State transitions live in one-operation domain services (`BookingConfirmer.confirm(booking)`, not `booking.confirm()`)
- Colocation by aggregate under `src/Domain/{Aggregate}/` — entity + every service that operates on it + repository interface in one folder
- All dependencies injected through constructor interfaces
- Repositories write SQL directly — no ORM
- Misplacement rules (where logic should NOT live) — see Anti-Patterns in the README

### Code style
- Explicit over implicit — dependencies injected, state checked, contracts are interfaces
- Immutability paired with statelessness — entities, value objects, DTOs, use cases, controllers all immutable. No setters, no in-place mutation. State transitions return new instances
- No premature abstraction — add structure when pain arrives, not before
- Use case interface per feature: `{Name}UseCaseInterface` with a single `execute()` method

### API design
- JSON envelope: `data` on success, `error` on failure — never both
- Validation in the controller (adapter logic), use cases assume valid input
- Exception-to-HTTP mapping: ValidationException→422, NotFoundException→404, DomainException→409, AuthenticationException→401, AuthorizationException→403
- JWT for stateless auth — use cases receive user ID, never tokens

### Reliability
- Idempotent operations — any step that might be retried must be safe to repeat
- State guards in use cases and domain services — check current state, don't assume history (entities are anemic, so guards do not live on them)
- Side effects: pick one of three — synchronous call (must succeed in-request, DB-only inside the transaction), in-process event (fire-and-forget, loss acceptable), or queued job (must eventually succeed)
- One async mechanism: the queue. Multi-step coordination is the handler's job, expressed against domain tables. No separate flow-execution framework
- Concurrency: unique constraint + idempotency key for creates; pessimistic locking for critical updates; optimistic locking for non-critical updates
- Third-party calls: local guard + vendor idempotency key (defense in depth)

### Data
- Schema-driven migrations with expand-contract pattern for changes
- Parameterized queries only — no string concatenation in SQL
- Cache reads not writes; invalidate explicitly on write; version cache keys
- Structured JSON logs — never log secrets, tokens, or unnecessary personal data

### Testing
- 100% coverage / 100% mutation is the **target state**, gated per interface by a `sealed` marker. During `exploration` (default) there is no test bar — writing tests against churning contracts is waste. Sealing is a deliberate human act; the interface itself is the contract. Value objects, pure parsers, and DTOs are tested from day one regardless (they *are* their own contract).
- Stubs by default, mocks only when the side effect IS the behavior
- Controller tests stub use cases; use case tests stub repositories
- Repository tests are BOTH unit (stub the data source for branching/orchestration logic) AND integration (real DB for SQL/schema contract) — neither replaces the other
- Repository unit tests assert behavior visible from return values, never SQL strings, parameter binding order, or driver call signatures
- Infrastructure integration tests for anything behind an interface (repos, cache, queue)

### Security
- HTTPS only, CORS whitelist, CSP headers
- No secrets in code or logs — environment variables only
- Dependency audits in CI — fail on known vulnerabilities

### Scaling
- Start layer-first; evolve to feature-first folders at ~50+ use cases
- Add DTOs when use cases take 8+ params
- Add domain events when use cases grow with side effects
- Keep: use case interfaces, one-transaction boundaries, interfaces for external services
