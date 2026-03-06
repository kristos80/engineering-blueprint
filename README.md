# Engineering Standards

An opinionated engineering blueprint for building applications. Language-agnostic, framework-agnostic. These standards apply to any project built on object-oriented, stateless request-response architecture.

## Design Principles

1. **Stateless.** No in-memory state between requests. Tokens provide identity, the database provides data. Scales horizontally by adding containers behind a load balancer.
2. **Transactional.** Every use case that writes data is a single DB transaction — all or nothing.
3. **Explicit over implicit.** Dependencies are injected, not resolved magically. State is checked, not assumed. Contracts are interfaces, not conventions.
4. **No premature abstraction.** Three similar lines of code are better than a helper nobody asked for. Add structure when pain arrives, not before.

## Project Structure

```
src/
├── Controller/       # HTTP adapters — receive requests, return responses
├── UseCase/          # Application logic — one class per business operation
├── Domain/           # Entities, value objects, repository interfaces
├── Shared/           # Common service layer — reusable services with no direct I/O
└── Infrastructure/   # Concrete I/O adapters — database, cache, filesystem, APIs
```

```
config/
├── container       # DI container — interface-to-implementation bindings
└── routes          # HTTP route definitions
```

## Architecture

### Layers

```
Controller → UseCase → Repository/Domain
```

- **Controllers** handle HTTP (request in, response out). Zero business logic. Request validation, auth context extraction, and error-to-HTTP mapping are OK (adapter logic).
- **Use cases** orchestrate business operations. One use case = one business operation. Pure application logic — no framework imports, no HTTP concepts.
- **Repositories** execute queries. Transaction-unaware — they just run SQL.
- **Domain** contains entities, value objects, and repository interfaces. Zero dependencies on outer layers.

### Dependency Direction

```
Controller  -->  UseCase (interface)  <--  UseCase (implementation)
                                               |
                                               v
                                           Domain
```

Controllers depend on use case interfaces. Use case implementations depend on domain. Nothing depends inward-to-outward.

### Dependency Injection

- Configured in a central container definition
- Every use case interface is explicitly bound to its implementation
- Controllers receive use cases through constructor injection
- External services (billing, SMS, email) are behind interfaces — implementations are swappable

### File Conventions

**Use Case** — each feature gets its own subdirectory:

```
src/UseCase/
└── BookingCreate/
    ├── BookingCreateUseCaseInterface
    └── BookingCreateUseCase
```

- Interface: `{Name}UseCaseInterface` with a single `execute()` method
- Implementation: `{Name}UseCase` — final, immutable class

**Controller:**

```
src/Controller/
├── AbstractController
└── BookingCreateController
```

### Use Case Rules

- Use cases do NOT call other use cases
- If two use cases share logic, extract it into a repository method or domain service
- Each use case class implements a corresponding interface (for DI and testing)
- Registered in the container as `Interface → Implementation`
- Pure application logic — no framework imports, no HTTP concepts

### Controller Conventions

- All controllers extend `AbstractController`
- Concrete controllers are final, immutable classes
- Implement a protected `invoke()` method (the base class handles HTTP dispatch)
- Use a shared `jsonResponse(response, data)` method for JSON output
- Route args accessed via request attributes
- Constructor injects use case interface(s)

### Transaction Boundaries

The use case owns the transaction — all database operations succeed or all roll back:

```
class CreateBookingUseCase implements CreateBookingUseCaseInterface

    constructor(
        transaction: TransactionInterface,
        userRepository: UserRepositoryInterface,
        bookingRepository: BookingRepositoryInterface
    )

    function execute(input: Map): Map
        return transaction.run(() =>
            user = userRepository.upsert(input["phone"], input["name"])
            booking = bookingRepository.create(user, input["service_id"], input["datetime"])
            return { "booking_id": booking.id }
        )
```

- `TransactionInterface` wraps begin/commit/rollback (swappable for testing)
- Repositories are transaction-unaware — they just run queries
- Simple use cases without multiple writes don't need a transaction

## API Design

### Response Format

All API responses follow a consistent JSON envelope:

Success:

```json
{
  "data": {
    "booking_id": "uuid-here"
  }
}
```

Error:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Human-readable description",
    "fields": {
      "phone": "Phone number is required",
      "datetime": "Must be a future date"
    }
  }
}
```

- `data` key on success, `error` key on failure — never both
- `fields` only present for validation errors (422)
- Never expose internals (stack traces, SQL, file paths) in error responses

### Error Handling

Exceptions propagate from use case to controller. The controller (or middleware) maps them to HTTP responses:

| Exception                 | HTTP Status | When                                           |
|---------------------------|-------------|------------------------------------------------|
| `ValidationException`     | 422         | Input fails validation rules                   |
| `NotFoundException`       | 404         | Entity not found                               |
| `DomainException`         | 409         | Business rule violation (e.g., double booking) |
| `AuthenticationException` | 401         | Missing or invalid token                       |
| `AuthorizationException`  | 403         | Valid token, insufficient permissions          |
| Any unhandled exception   | 500         | Bug — log it, return generic message           |

Use cases throw domain/validation exceptions. Controllers never throw — they catch and map to HTTP.

### Input Validation

- **Validate in the controller.** It's adapter logic — reject malformed HTTP input before it reaches the use case.
- **Use cases assume valid input.** They received it from a trusted boundary (the controller).
- Return 422 with field-level errors in the `fields` object.

```
class BookingCreateController extends AbstractController

    function invoke(request: Request, response: Response): Response
        body = request.parsedBody()

        errors = {}
        if body["phone"] is empty
            errors["phone"] = "Phone number is required"

        if errors is not empty
            return errorResponse(response, 422, "VALIDATION_FAILED", "Invalid input", errors)

        result = useCase.execute(body)
        return jsonResponse(response, result)
```

### Authentication & Authorization

- **JWT for stateless auth.** Token contains user ID and role. No server-side sessions.
- **Middleware extracts auth context** from the `Authorization` header and attaches it to the request as an attribute.
- **Controllers access auth via request attribute:** `request.getAttribute("auth")`
- **Role-based access:** middleware checks role before the controller runs. Controllers don't check permissions — middleware already did.

```
Request → AuthMiddleware (extract JWT, attach user context, check role) → Controller → UseCase
```

- Auth is adapter logic (middleware/controller layer), not business logic.
- Use cases receive a user ID as input, not a token. They don't know about JWT.

### API Versioning

- **URL prefix: `/api/v1/`** — add when mobile apps exist (can't force-update clients).
- New version only for breaking changes (removed fields, changed semantics).
- Support N-1 version minimum. Deprecate before removing.
- Until mobile apps exist, no versioning needed — the frontend deploys with the backend.

### Rate Limiting

- Middleware-level. Per-user (authenticated) and per-IP (anonymous).
- Add when public traffic justifies it — not day one.
- Return `429 Too Many Requests` with `Retry-After` header.

## Reliability

### Event System

Use a lightweight event dispatcher with named actions and priority-based execution. No heavyweight framework event systems needed.

```
// Bootstrap — register listeners
dispatcher.onAction("booking.created", sendSmsListener)
dispatcher.onAction("booking.created", notifyProfessionalListener)

// In use case — dispatch after the main operation
dispatcher.dispatch("booking.created", booking)
```

- Events handle **side effects only** (SMS, notifications, analytics) — not core business logic
- The use case dispatches events; listeners handle consequences
- Pass typed event objects when the payload gets complex: `dispatch("booking.created", new BookingCreated(id))`
- Inject the dispatcher interface into use cases via DI

### Critical Flows

For multistep operations where partial completion is unacceptable, use a **flow execution tracker** — a database table that records progress through steps. If the process crashes or a step fails, you know exactly where it stopped and can resume.

Examples: payment + booking, multiservice provisioning, onboarding workflows with external API calls.

```
flow_executions
├── id
├── type           -- "payment_booking", "professional_onboarding", etc.
├── version        -- flow definition version (for safe evolution)
├── reference_id   -- contextual UUID
├── steps          -- {"step_1": "completed", "step_2": "completed", "step_3": "pending"}
├── status         -- running | completed | failed
├── created_at
└── updated_at
```

**Sequential within a flow, concurrent across flows.** Steps within one flow run in order (step 1 → 2 → 3). Multiple workers process different flows simultaneously.

```sql
-- Worker picks up a stuck flow — row lock prevents double-processing
SELECT * FROM flow_executions
WHERE status = 'running'
AND updated_at < NOW() - INTERVAL 5 MINUTE
FOR UPDATE SKIP LOCKED
LIMIT 1;
```

- `FOR UPDATE` locks the row — no other worker can grab it
- `SKIP LOCKED` — other workers don't wait, they grab the next available row
- `updated_at` acts as a heartbeat — worker updates it as it progresses through steps
- If a worker crashes, the row goes stale, another worker picks it up and resumes from the last completed step

**What goes through flow execution:** any multistep operation where partial completion causes data integrity issues (e.g., payment charged but booking not created).

**What does NOT:** SMS, notifications, emails, analytics — these go through the event system with a queue for retry. A failed notification is an ops issue, not a data integrity issue.

**Flow versioning:** step sequences are defined in code per version. New flows get the current version. In-progress flows complete with the version they started with. Old version code stays until all flows of that version are completed.

### State Guards & Idempotency

**Guard on state, not on history.** Don't assume a previous step ran — check the entity's current state before allowing an operation:

```
// Bad — assumes charge() was called before this
function ship(order: Order): void
    // just ships, hopes payment happened

// Good — checks state, rejects invalid transitions
function ship(order: Order): void
    if order.status != OrderStatus.PAID
        throw InvalidOrderStateException("Cannot ship unpaid order")
```

State guards on entities enforce valid transitions. The flow tracker records which steps completed. Together they prevent both "step was skipped" and "step ran out of order."

**Idempotent operations.** Any step that might be retried (by a worker resuming a flow, a queue redelivering a job, or a user double-clicking) must produce the same result when called twice:

```
// Bad — charges twice on retry
function charge(orderId: String, amount: Int): void
    paymentProvider.charge(orderId, amount)

// Good — checks before acting
function charge(orderId: String, amount: Int): void
    if paymentRepository.hasCharge(orderId)
        return
    paymentProvider.charge(orderId, amount)
```

This matters for the flow tracker — a worker resumes from the last incomplete step, but the step may have completed while the status update didn't persist. The step runs again and must be safe to repeat.

**Third-party idempotency — defense in depth.** For external vendor calls (payments, SMS, etc.), use two layers:

1. **Local guard (always)** — check your database before calling the vendor. Prevents the external call entirely on retry.
2. **Vendor idempotency key (when supported)** — pass a reference (e.g., order UUID) as the idempotency key. If your local check fails (race condition), the vendor deduplicates.

```
// Layer 1: local guard
existing = paymentRepository.findByReference(orderReference)
if existing != null
    return existing

// Layer 2: vendor idempotency key
result = billingProvider.charge(
    amount: amount,
    currency: "eur",
    idempotencyKey: orderReference
)

// Persist result for future local guards
paymentRepository.store(orderReference, result)
```

Neither layer alone is sufficient: local guard misses "DB write failed after vendor call", vendor key misses "no vendor support". Together they cover every failure mode.

**Vendor selection criterion:** any payment or financial provider that doesn't support idempotency keys is a red flag.

### Data Evolution Safety

Code and data evolve at different speeds. These are the breaking points and how to handle them:

| Risk               | Example                                         | Prevention                                                            |
|--------------------|-------------------------------------------------|-----------------------------------------------------------------------|
| JSON columns       | Code expects a key old rows don't have          | Version field in JSON, deserializer handles each version              |
| Queue payloads     | Old jobs in queue, new worker expects new shape | Payload versioning: `payload["version"] ?? 1`                         |
| Schema changes     | Code references column that doesn't exist yet   | Expand-contract: add column → backfill → remove old                   |
| API contracts      | Backend removes field, old mobile app crashes   | Contract tests in CI; deprecate before removing                       |
| Enum/status values | New status added during rolling deploy          | New code handles unknown values gracefully before old code is retired |
| Cache              | Cached data has old shape                       | Version key in cached data, or invalidate on deploy                   |

**Expand-contract pattern** for any schema or contract change — never rename/remove in one deploy:

```
Deploy 1 (expand):  Add new column/field, code writes to both old and new
Deploy 2 (migrate): Backfill old data
Deploy 3 (contract): Remove old column/field, code only uses new
```

## Infrastructure

### Background Jobs & Queues

Database-backed queue. No external queue dependency until load requires it.

```
jobs
├── id
├── type            -- "send_sms", "sync_calendar", etc.
├── payload         -- JSON with version field
├── status          -- pending | processing | completed | failed
├── attempts        -- retry count
├── max_attempts    -- per job type
├── next_retry_at   -- exponential backoff
├── created_at
└── updated_at
```

Workers use the same pattern as the flow tracker:

```sql
SELECT * FROM jobs
WHERE status = 'pending'
AND next_retry_at <= NOW()
FOR UPDATE SKIP LOCKED
LIMIT 1;
```

- Event listeners can dispatch to the queue for async processing
- Failed jobs retry with exponential backoff (e.g., 1min, 5min, 30min)
- After `max_attempts`, mark as failed — surface in admin/monitoring
- Job handlers must be idempotent (same job may run twice)

### Database Strategy

**No ORM.** Repositories write SQL directly. ORMs hide what queries run, make performance unpredictable, and create a false domain model that couples your entities to database structure. Our architecture already separates concerns: use cases orchestrate, repositories query, domain models are pure. An ORM adds a layer that competes with this design instead of complementing it.

**Schema-driven migrations.** The database schema is defined declaratively in a single source-of-truth file. A migration tool diffs the declared schema against the current database and generates migration SQL:

```
schema definition  →  diff tool  →  migration SQL  →  applied to database
                                         ↑
                               reviewed before applying
```

- The migration tool is used strictly for schema management — never as an ORM or query builder
- Repositories use the database driver with prepared statements directly
- Run migrations in CI against production-like schema
- Never edit a migration that has been deployed. Modify the schema definition and generate a new one.

**Indexing:**

- Index what you query, not what you might query
- Every `WHERE` clause and `JOIN` condition in production queries should have a supporting index
- Review slow query logs periodically — add indexes based on real usage, not speculation

**Connections:**

- Use connection pooling in production
- Repositories receive a connection interface — never open connections directly

### Caching

- **Cache reads, not writes.** Cache is a performance optimization, never a source of truth.
- **Start without a cache layer.** Add an external cache when actual load demands it.
- **Invalidate explicitly on write** — not time-based TTL (stale data is worse than slow data for most operations).
- **Cache key includes a version** for safe deploys: `v3:professional:slug:glamour-by-sofia`
- **Never cache user-specific data in shared caches** without proper key scoping.

### Logging & Observability

**Structured JSON logs.** Machine-parseable, greppable, aggregatable.

```json
{
  "level": "error",
  "message": "Payment charge failed",
  "context": {
    "order_id": "uuid",
    "provider": "billing_provider",
    "error_code": "card_declined"
  },
  "timestamp": "2026-03-06T12:00:00Z"
}
```

**What to log:**

- Incoming requests (method, path, status code, duration)
- Outgoing calls to external services (provider, duration, success/failure)
- All errors and exceptions with context
- Business events (booking created, payment processed) — audit trail

**What NOT to log:**

- Passwords, tokens, API keys, card numbers — ever
- Full request/response bodies in production (log selectively in debug)
- Personal data beyond what's needed for debugging (GDPR)

**Log levels:** `error` for bugs and failures, `warning` for degraded service (retry succeeded), `info` for business events, `debug` for development only.

Use a standard logging interface — implementation is swappable (file, stdout, external service).

### CI/CD Pipeline

**Every PR must pass:**

- Unit tests (100% coverage)
- Static analysis at max level
- Mutation testing
- Database migrations against production-like schema
- Frontend build + lint

**No merge without green CI.** No exceptions, no "I'll fix it later."

**Deployment:**

```
Build → Run migrations → Deploy code → Health check → Route traffic
```

- Migrations run before new code is live (expand-contract ensures backwards compatibility)
- Health check endpoint confirms app is functional before routing traffic
- Rollback = deploy previous version (migrations are forward-only, compensate with new migrations)

### Security

- **Parameterized queries only.** No string concatenation in SQL — ever. Repositories use prepared statements.
- **Escape all output.** Context-appropriate escaping for HTML, JSON, URLs. No raw user input in templates.
- **HTTPS only.** Redirect HTTP → HTTPS. Set `Strict-Transport-Security` header.
- **CORS configured explicitly.** Whitelist allowed origins — never `*` in production.
- **No secrets in code or logs.** Environment variables for credentials. Secret files never committed.
- **Dependency audits.** Run audit tools in CI — fail on known vulnerabilities.
- **Content Security Policy.** Restrict inline scripts, external resources. Prevents XSS escalation.

## Subscriptions & Payments

Approach: **external billing provider** behind interfaces. The provider handles recurring billing, dunning, invoicing, and tax. Our app handles business logic and feature gating.

### Separation of Concerns

| Concern                                        | Owner                              |
|------------------------------------------------|------------------------------------|
| What features each plan includes               | Our database                       |
| Recurring billing, retries, invoices, tax      | Billing provider                   |
| Subscription status for feature gating         | Our database (synced via webhooks) |
| One-time purchases (boosts, featured listings) | Billing provider + our database    |

### Interfaces (provider-agnostic)

Use cases depend on billing interfaces, not a specific provider. If the provider changes, only the implementation swaps:

```
BillingProviderInterface          -- create checkout session, cancel subscription
SubscriptionRepositoryInterface   -- local subscription state (status, plan, period)
```

### Data Model

```
plans                              subscriptions
├── id                             ├── id
├── name                           ├── owner_id
├── features / limits              ├── plan_id
├── billing_provider_price_id      ├── billing_provider_subscription_id
└── price                          ├── status (active / past_due / canceled)
                                   └── current_period_end

top_ups
├── id
├── owner_id
├── type ("boost_listing", "featured")
└── expires_at
```

### Flow

1. User picks a plan → use case calls `BillingProviderInterface.createCheckoutSession()`
2. Provider handles payment, card entry, tax
3. Provider fires webhook → `CreateSubscriptionUseCase` stores subscription locally
4. Feature gating checks local `subscriptions.status` — no external API call needed
5. Top-ups follow the same pattern as one-time charges

Architecture is provider-agnostic via interfaces.

## Testing

### Requirements

- **100% code coverage.** Every class, every method, every branch.
- **100% mutation score** where applicable. Surviving mutants indicate weak assertions.
- **Every class is testable in isolation.** All dependencies injected through constructor interfaces.

### Stubs vs Mocks

**Default to stubs. Use mocks only when the call IS the behavior.**

- **Stub** — a test double that controls input. "Given this dependency returns X, assert my code produces Y."
- **Mock** — a test double that verifies communication. "Assert my code called this dependency with these arguments."

**Use stubs** for most tests — they survive internal refactors:

```
repo = createStub(BookingRepositoryInterface)
repo.on("create").willReturn(booking)

result = useCase.execute(input)
assertEqual(bookingId, result["booking_id"])
```

**Use mocks** only when the side effect IS the thing you're testing:

```
// "Did we actually charge the provider?" — can't observe from return value
billing = createMock(BillingProviderInterface)
billing.expectOnce("charge").with(userId, amount)
```

**Why not use mocks for everything?** A mock without expectations behaves identically to a stub at runtime. The distinction is about **intent signaling**: a stub tells the reader "this is just a placeholder, no expectations to look for." A mock signals "expectations are verified somewhere below." Modern testing frameworks enforce this — creating a stub prevents you from accidentally adding expectations.

Over-mocking makes tests brittle — an internal call reorder breaks tests even though behavior is unchanged. Test **what came out**, not **how it got there**.

*Ref: [Sebastian Bergmann — Testing with(out) Dependencies](https://phpunit.expert/articles/testing-with-and-without-dependencies.html)*

### Isolation Per Layer

- **Controller tests** stub the use case interface. Assert correct HTTP response.
- **Use case tests** stub repository/service interfaces. No database, no external calls. Assert business rules. Mock only for critical side effects (payments, external APIs).
- **Domain tests** need no doubles. Entities and value objects are pure.
- **Repository tests** are integration tests — they run against a real test database. Stubbing the database in a repository test tests nothing.

### Infrastructure Integration Tests

Any class whose purpose is to talk to an external system needs integration tests against that system. This includes repositories, cache implementations, queue adapters, and external API clients.

**Repository tests** — against a real test database:

```
// Each test runs in a transaction that rolls back — no cleanup needed
connection.beginTransaction()

repo = new BookingRepository(connection)
booking = repo.create(user, serviceId, datetime)
found = repo.findById(booking.id)

assertEqual(booking.id, found.id)
assertEqual(serviceId, found.serviceId)

connection.rollback()
```

**Cache tests** — against a real cache instance (e.g., Redis):

```
cache = new RedisCacheAdapter(redisConnection)

cache.set("booking:123", data)
found = cache.get("booking:123")
assertEqual(data, found)

cache.invalidate("booking:123")
assertNull(cache.get("booking:123"))

// Clean up test keys
cache.invalidate("booking:123")
```

**The principle:** if the class implements an infrastructure interface (`CacheInterface`, `RepositoryInterface`, `QueueInterface`), it gets integration tests against the real backend. Use cases and controllers never touch these systems directly — they go through interfaces, which are stubbed in unit tests.

- **Real infrastructure in CI** — test database, test Redis, etc. spun up alongside the app
- **Repository tests wrap in transaction + rollback** — tests don't affect each other
- **Cache/queue tests clean up after themselves** — explicit delete of test keys/jobs
- **Catches what unit tests can't:** wrong SQL, serialization issues, TTL behavior, connection failures
- **Separate from unit tests** — slower, requires infrastructure, runs in CI alongside unit tests

### Test Structure

Tests mirror `src/`:

```
tests/
├── Controller/
│   └── BookingCreateControllerTest
├── UseCase/
│   └── BookingCreate/
│       └── BookingCreateUseCaseTest
├── Domain/
│   └── Booking/
│       └── BookingTest
└── Infrastructure/
    ├── Repository/
    │   └── BookingRepositoryTest          # integration — real DB
    └── Cache/
        └── RedisCacheAdapterTest          # integration — real Redis
```

## Scaling Guidelines

Current structure is **layer-first** — correct at small scale.

**When to evolve (and not before):**

| Change                    | Trigger                                                             | What to do                                                      |
|---------------------------|---------------------------------------------------------------------|-----------------------------------------------------------------|
| Feature-first folders     | ~50+ use cases, navigation becomes painful                          | Move to `src/Booking/Controller/`, `src/Booking/UseCase/`, etc. |
| Request/Response DTOs     | Use case takes 8+ params or same shape passed across layers         | Replace arrays/maps with typed DTOs                             |
| Split Infrastructure      | 10+ adapters, navigation becomes painful                            | Subdirectories: `Infrastructure/Cache/`, `Infrastructure/Queue/`, etc. |
| Domain events             | Use case grows with side effects (SMS, notifications, availability) | Dispatch events, handle in listeners                            |
| Query objects (CQRS-lite) | Complex reads diverge from writes                                   | Read-only use cases that query directly                         |

**What to keep regardless of scale:**

- Use case interfaces — contracts, not overhead. Enable test doubles, explicit DI, and readable boundaries.
- One use case = one transaction boundary
- Repositories and external services behind interfaces

## AI-Assisted Engineering

> *"AI doesn't replace engineering discipline. It amplifies what your architecture already provides. In well-governed systems, it accelerates delivery. In weakly structured systems, it accelerates entropy."*
> — [thePHP.cc](https://thephp.cc/welcome#ai) (Sebastian Bergmann, Stefan Priebsch, Arne Blankerts)

AI is a force multiplier for existing architecture, not a productivity shortcut.

- **Architectural boundaries are guardrails.** Module ownership, stable interfaces, and dependency rules constrain AI-generated code.
- **Quality gates are non-negotiable.** Tests, static analysis, mutation testing, and CI thresholds apply equally to human and AI contributions.
- **Code must remain understandable.** Developers stay accountable — AI output is reviewed and maintained like any other code.
- **AI reinforces discipline.** Faster feedback loops, better tests, clearer code. Backed by architecture and policies that prevent surprises.
