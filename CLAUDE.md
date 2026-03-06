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
- All dependencies injected through constructor interfaces
- Repositories write SQL directly — no ORM

### Code style
- Explicit over implicit — dependencies injected, state checked, contracts are interfaces
- No premature abstraction — add structure when pain arrives, not before
- Final, immutable classes for use cases and controllers
- Use case interface per feature: `{Name}UseCaseInterface` with a single `execute()` method

### API design
- JSON envelope: `data` on success, `error` on failure — never both
- Validation in the controller (adapter logic), use cases assume valid input
- Exception-to-HTTP mapping: ValidationException→422, NotFoundException→404, DomainException→409, AuthenticationException→401, AuthorizationException→403
- JWT for stateless auth — use cases receive user ID, never tokens

### Reliability
- Idempotent operations — any step that might be retried must be safe to repeat
- State guards on entities — check current state, don't assume history
- Flow execution tracker for multistep operations where partial completion is unacceptable
- Event system for side effects (SMS, notifications, analytics) — not core business logic
- Third-party calls: local guard + vendor idempotency key (defense in depth)

### Data
- Schema-driven migrations with expand-contract pattern for changes
- Parameterized queries only — no string concatenation in SQL
- Cache reads not writes; invalidate explicitly on write; version cache keys
- Structured JSON logs — never log secrets, tokens, or unnecessary personal data

### Testing
- 100% code coverage, 100% mutation score where applicable
- Stubs by default, mocks only when the side effect IS the behavior
- Controller tests stub use cases; use case tests stub repositories; repository tests use real DB
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
