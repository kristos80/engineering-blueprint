# Engineering Standards

An opinionated engineering blueprint for building applications. Language-agnostic, framework-agnostic. These standards apply to any project built on object-oriented, stateless request-response architecture.

## How to Use This Blueprint

This is a **standalone reference** — a map for engineering decisions across projects. It is not tied to any specific codebase.

**For new projects:** adopt these standards from day one. Reference this blueprint in your project's `CLAUDE.md` to have AI-assisted development follow these rules automatically.

**For existing projects:** adopt selectively. Use the table of contents to find the section relevant to your current decision, and apply what fits.

**For AI-assisted development:** point Claude Code at this blueprint by adding the following to your project's `CLAUDE.md`:

```
## Engineering Standards
Follow the engineering blueprint at: /absolute/path/to/engineering-blueprint/README.md
```

## Table of Contents

- [Design Principles](#design-principles)
- [Project Structure](#project-structure)
- [Architecture](#architecture)
  - [Layers](#layers)
  - [Dependency Direction](#dependency-direction)
  - [Dependency Injection](#dependency-injection)
  - [File Conventions](#file-conventions)
  - [Use Case Rules](#use-case-rules)
  - [Controller Conventions](#controller-conventions)
  - [Transaction Boundaries](#transaction-boundaries)
    - [Synchronous (Single Connection)](#synchronous-single-connection)
    - [Async with Connection Pools](#async-with-connection-pools)
- [API Design](#api-design)
  - [Response Format](#response-format)
  - [Error Handling](#error-handling)
  - [Input Validation](#input-validation)
  - [Authentication & Authorization](#authentication--authorization)
  - [API Versioning](#api-versioning)
  - [Rate Limiting](#rate-limiting)
- [Reliability](#reliability)
  - [Event System](#event-system)
  - [Critical Flows](#critical-flows)
  - [State Guards & Idempotency](#state-guards--idempotency)
  - [Data Evolution Safety](#data-evolution-safety)
  - [Backward Compatibility Testing](#backward-compatibility-testing)
- [Infrastructure](#infrastructure)
  - [Background Jobs & Queues](#background-jobs--queues)
  - [Database Strategy](#database-strategy)
  - [Caching](#caching)
  - [Logging & Observability](#logging--observability)
  - [CI/CD Pipeline](#cicd-pipeline)
    - [Hotfix Process](#hotfix-process)
  - [Deployment](#deployment)
    - [Release Identification](#release-identification)
    - [Deployment Strategy](#deployment-strategy)
    - [Rollback](#rollback)
    - [Health Checks](#health-checks)
  - [Security](#security)
- [Subscriptions & Payments](#subscriptions--payments)
- [Testing](#testing)
  - [Requirements](#requirements)
  - [Stubs vs Mocks](#stubs-vs-mocks)
  - [Isolation Per Layer](#isolation-per-layer)
  - [Infrastructure Integration Tests](#infrastructure-integration-tests)
  - [Test Structure](#test-structure)
- [Scaling Guidelines](#scaling-guidelines)
- [On Frameworks](#on-frameworks)
- [Code Reviews](#code-reviews)
- [AI-Assisted Engineering](#ai-assisted-engineering)

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

Controllers depend on use case interfaces. Use case implementations depend on domain. Nothing depends on inward-to-outward.

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

#### Synchronous (Single Connection)

In traditional synchronous request-response frameworks (e.g., PHP with PDO, Java with JDBC), there is one database connection per request. The transaction is implicit on that connection — `beginTransaction()`, repositories execute queries, `commit()`. Every query between begin and commit shares the same connection by default, because there's no other connection to use. Repositories don't need to know about the transaction — the single-connection-per-request model handles it invisibly.

```
// Synchronous — one connection, implicit transaction scope
transaction.run(() =>
    repoA.insert(...)   // uses the single PDO/JDBC connection
    repoB.insert(...)   // same connection — same transaction
)
```

#### Async with Connection Pools

In async runtimes with connection pools (e.g., PHP with AMPHP/ReactPHP, Node.js, Go), the single-connection assumption breaks. A pool holds multiple open connections, and each query grabs whichever is free. If a use case inserts a customer on connection 3 and an order on connection 7, they're in separate transaction scopes — rolling back connection 3 won't undo connection 7's write.

The solution is a **connection holder** — a wrapper that normally delegates to the pool, but during a transaction gets swapped to the specific transaction connection. Repositories call `holder.get()` without knowing whether they're hitting the pool or a transaction connection.

```
// Async — connection holder swaps pool for transaction connection
class ConnectionHolder
    pool: ConnectionPool
    transactionExecutor: Executor | null

    function get(): Executor
        return transactionExecutor ?? pool

class Transaction implements TransactionInterface
    function run(callback):
        tx = pool.beginTransaction()          // opens transaction on a specific connection
        holder.setTransactionExecutor(tx)     // redirect all queries to this connection
        try:
            result = callback()               // repositories call holder.get() → tx
            tx.commit()
            return result
        catch:
            tx.rollback()
            throw
        finally:
            holder.setTransactionExecutor(null)  // back to pool mode
```

- Outside a transaction: `holder.get()` returns the pool — queries go to any available connection
- Inside a transaction: `holder.get()` returns the transaction connection — all queries are atomic
- Repositories are unaware of which mode they're in — same code, same interface, different behavior based on context

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

### Backward Compatibility Testing

**This applies to unstructured data inside structured columns** — JSON columns, queue payloads, and cached objects. These are the formats the database cannot enforce.

Schema-level changes (adding columns, renaming, changing types) don't need this discipline. Migrations either complete with defaults and backfills or fail — the database enforces the contract. End-to-end tests validate that code and schema stay in sync.

But a JSON column is opaque to the database. Old rows keep their old shape forever unless explicitly migrated. Queue jobs serialized before a deploy sit alongside jobs serialized after. The database won't reject a missing key or a renamed field — your code will just break at runtime. This is where backward compatibility must be enforced in code and tests.

#### Single-entry-point deserializer

Code never reads versioned data (JSON columns, queue payloads, cached objects) directly. Every versioned format has a **deserializer** — a single factory method that accepts any known version and returns the current domain object:

```
class ProfessionalPayload

    static function fromArray(raw: Map): ProfessionalPayload
        version = raw["version"] ?? 1

        if version == 1
            return new ProfessionalPayload(
                name: raw["name"],
                contact: raw["phone"],          // v1 used "phone"
                specialties: []                  // v1 had no specialties
            )

        if version == 2
            return new ProfessionalPayload(
                name: raw["name"],
                contact: raw["phone"],
                specialties: raw["specialties"]
            )

        if version == 3
            return new ProfessionalPayload(
                name: raw["name"],
                contact: raw["contact"],         // v3 renamed "phone" → "contact"
                specialties: raw["specialties"]
            )

        throw UnknownPayloadVersionException(version)
```

All code that reads the data calls `ProfessionalPayload::fromArray()`. No caller inspects the raw JSON. This gives you one place to maintain, one place to test, and one place that breaks if a version is mishandled.

#### Fixture-per-version tests

For every versioned data format, maintain a test fixture for each historical version. Each fixture is a real snapshot of what the database, queue, or cache actually contains:

```
tests/fixtures/
  professional_payload_v1.json   # original shape
  professional_payload_v2.json   # added "specialties"
  professional_payload_v3.json   # renamed "phone" → "contact"
```

The deserializer test runs every fixture and asserts it normalizes to the same domain object:

```
/** @dataProvider provideAllPayloadVersions */
function testDeserializesAllVersions(fixture: String, expected: Map): void
    raw = jsonDecode(readFile(fixture))
    result = ProfessionalPayload.fromArray(raw)

    assertEqual(expected["name"], result.name)
    assertEqual(expected["contact"], result.contact)
    assertEqual(expected["specialties"], result.specialties)
```

If a new version breaks deserialization of any previous version, this test fails.

#### Rules

1. **Never delete old fixtures.** They represent data that exists in production. Removing a fixture removes the proof that old data still works.
2. **Every new version requires a new fixture.** Adding a version without a fixture is incomplete — CI coverage gates will catch the untested branch.
3. **The deserializer rejects unknown versions explicitly.** Throw on `version > latest` rather than silently falling through — this catches payloads from a newer deploy reaching an older worker during rolling updates.

#### Why this forces backward compatibility

Three layers enforce the discipline:

| Layer | What it enforces |
|-------|------------------|
| **Single-entry-point deserializer** | Structural — there is one place to break, one place to fix. No caller can bypass the version handling. |
| **Fixture-per-version tests in CI** | Automated — adding a version without a fixture fails coverage. Breaking an old version's deserialization fails the test. |
| **Expand-contract deploy sequence** | Procedural — you cannot remove old data before new code handles both shapes, and you cannot remove old code until backfill is verified. |

No single layer is sufficient alone. The deserializer centralizes the logic. The fixtures prove every version works. The expand-contract sequence ensures old and new coexist long enough for the migration to complete.

**Schema-level changes don't need this.** The database itself is the enforcement layer — migrations succeed with correct defaults or fail. End-to-end tests against a real database validate that code and schema agree. The discipline above exists precisely because JSON columns, queues, and caches lack that built-in enforcement.

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

**No merge without green CI.** No exceptions, no "I'll fix it later" — except hotfixes (see below).

#### Hotfix Process

When production is broken and the fix is obvious, you don't wait for mutation testing. But "skip CI" is never the answer — a reduced pipeline is.

1. **Branch from the production SHA** — not from `main`, which may contain unreleased changes
2. **Reduced CI**: unit tests for the affected area + static analysis. Skip mutation testing, skip full suite.
3. **Deploy the hotfix** through the normal deployment pipeline
4. **Merge back to `main` through full CI within 24 hours.** If full CI fails, fix it immediately — untested code does not stay in production.
5. **Log the incident**: what broke, what the fix was, why the fast track was used

The rule: *full CI before merge. Hotfixes get reduced CI before deploy, full CI before merge to main.*

Hotfixes that cannot pass even the reduced pipeline do not ship. If you cannot write a fix that passes unit tests and static analysis, the fix is not ready.

### Deployment

#### Release Identification

The git SHA is your version. It is unique, unforgeable, and already in your tooling.

- **Git SHA (or short SHA) identifies every release.** Build artifacts, deployment logs, and health check endpoints should expose the SHA that is running.
- **Git tags for milestones** — when you need a human-readable reference point (incident postmortems, "deploy the version from before X"). Use date-based tags: `release/2025-03-24`, or tag after significant features land.
- **SemVer only when you publish.** If the application becomes a library, ships a public API with external consumers, or has mobile clients that pin to versions — adopt SemVer then. Until you have consumers who independently choose when to upgrade, a version number is ceremony without a reader.

#### Deployment Strategy

```
Build → Run migrations → Deploy code → Health check → Route traffic
```

- Migrations run before new code is live (expand-contract ensures backwards compatibility)
- Health check confirms the instance is functional before it receives traffic

**Rolling deploy is the default.** Replace instances one at a time behind the load balancer. The architecture is stateless — any instance can handle any request. Zero downtime with no extra infrastructure.

| Strategy | Trigger | What it gives you |
|----------|---------|-------------------|
| **Rolling deploy** | Default — start here | Zero downtime, simple, no extra infra |
| **Blue-green** | Need instant rollback (seconds, not minutes) | Two environments, swap the router. Rollback is a LB switch, not a redeploy |
| **Canary** | High traffic, high risk changes, observability maturity in place | Route small % of traffic to new version, watch metrics, expand gradually |

Adopt blue-green or canary when rolling deploys cause pain — not before. Same principle as the scaling guidelines.

#### Rollback

Rollback is deploying the previous known-good SHA. It is code rollback only — never migration rollback.

- **Tag the current production SHA before every deploy.** If the new version fails, you know exactly what to redeploy.
- **Migrations are forward-only.** Expand-contract ensures the old code works with the new schema, so rolling back the code is always safe.
- **If you need to undo a data change, write a new migration forward.** Do not manually reverse migrations — that path leads to schema drift.
- **Rollback is not free.** If you deployed a "contract" migration (dropped a column), the old code that reads that column cannot be rolled back to. This is why expand-contract separates expand and contract into different releases.

#### Health Checks

Two endpoints, two purposes:

- **Liveness** (`/health/live`): "Is the process alive?" Returns 200 if the application is running. No dependency checks. Used by the orchestrator to decide whether to restart the container.
- **Readiness** (`/health/ready`): "Can it serve traffic?" Checks database connectivity, critical dependencies. Used by the load balancer to decide whether to route traffic to this instance.

The distinction matters for rolling deploys: an instance that is alive but still running migrations or warming up should not receive traffic. Liveness keeps it from being killed; readiness keeps it out of the rotation until it is ready.

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

## On Frameworks

This blueprint does not require a framework. It is not against frameworks either — but it is worth being honest about what they cost.

Most of what this blueprint describes is simple to build from scratch. A DI container is a map of interfaces to factory functions. A router is a list of patterns matched against a request path. Middleware is a chain of callables. A JSON response envelope is a helper that wraps an array. A transaction wrapper is begin/commit/rollback behind an interface. A database-backed queue is a table with `FOR UPDATE SKIP LOCKED`. None of these require tens of thousands of lines of framework code.

Frameworks ship enormous dependency trees to cover every possible use case — most of which you will never need. You inherit their abstractions, their conventions, their upgrade cycles, and their bugs. When your needs diverge from the framework's assumptions — and they will — you spend time fighting the framework instead of solving your problem. The debugging surface area grows from "your code" to "your code plus everything the framework does behind the scenes."

**What you actually need from external code:**

| Concern | What to reach for |
|---------|-------------------|
| HTTP request/response | A thin PSR-compliant library or standard-library HTTP module — not a full-stack framework |
| Routing | A standalone router (~200 lines of code, or a small library) |
| DI container | A simple container or write your own — interface-to-factory bindings are trivial |
| Database access | The language's database driver with prepared statements |
| Template rendering | A standalone template engine, if you serve HTML at all |
| Testing | A test framework — this is the one dependency worth taking seriously |

**What you don't need a framework for:**

- Use cases, controllers, repositories, domain objects — these are your code, structured by your architecture
- Validation, error handling, response formatting — straightforward to write, and you own the behavior
- Middleware, event dispatching, job queues — simple patterns, small implementations
- Auth middleware, JWT handling — a JWT library plus a few lines of middleware

The value of owning these pieces is not about avoiding dependencies on principle. It is about understanding every line that runs in production. When something breaks at 2 AM, you are debugging code you wrote — not tracing through a framework's internals trying to figure out which of its 400 classes intercepted your request.

Frameworks are useful when you need to ship something fast with a team that already knows the framework. They are less useful when you have clear architectural standards, a small dependency surface, and the discipline to build what you need. This blueprint assumes the latter.

## Code Reviews

Code reviews catch what CI cannot: architectural drift, unclear intent, premature complexity, missing context. They are not a substitute for tests, static analysis, or the backward compatibility gates described elsewhere — those run first.

### Prerequisites

- **CI must be green before review.** Reviewer time is not spent on what the pipeline already catches (formatting, type errors, coverage, mutation score, security audit, dependency vulnerabilities).
- **The author self-reviews the diff first.** If you would not approve it, do not request review.
- **One concern per PR.** Large PRs get reviewed superficially. Split before requesting review.

### What blocks merge

- One approval from a reviewer familiar with the area. Two when the change crosses domains (auth, billing, migrations) or affects other teams.
- All CI checks green.
- Open threads resolved by the author, not auto-closed.

### Author responsibilities

- **Description states the *why*, not the *what*** — the diff already shows the what.
- **Link the ticket or incident.** For bug fixes, include the reproduction steps and the root cause.
- **Flag anything that deviates from the default deploy flow.** Migrations always run before code (see [Deployment Strategy](#deployment-strategy)) — that does not need to be called out. Do call out: "depends on PR #X", "ships behind feature flag", "deploy 2 of 3 in an expand-contract sequence", "requires backfill job after deploy".

### Reviewer focus

The reviewer is checking what the author cannot see in their own code: drift from the architecture and gaps in intent.

| Check | What to look for |
|-------|------------------|
| **Familiar with the feature** | Understand what the change is for before reading the diff. A diff without context invites surface-level review. |
| **Right layer** | Business logic in the use case, not the controller. SQL in the repository, not the use case. External calls behind an interface. |
| **Right scope** | Use cases do not call other use cases. Controllers carry no business logic. Shared logic extracted into repository methods or domain services. |
| **Root cause, not symptom** | The change addresses the cause. A deliberate workaround needs a comment explaining why the root cause was deferred. |
| **Intent is clear** | Names and structure communicate what the code does. Comments explain *why* only where the why is non-obvious. |
| **Tests reflect behavior** | New behavior has tests. Test names describe behavior, not implementation. Stubs by default, mocks only when the side effect is the behavior. |
| **No premature complexity** | New patterns, abstractions, or dependencies justified by current pain — not anticipated needs. Three similar lines is better than a premature abstraction. |

### What is already enforced elsewhere — do not relitigate

Review confirms these were followed; it does not duplicate their work:

- **Backward compatibility of unstructured data** (JSON columns, queue payloads, cache) — covered by deserializer + fixture-per-version tests. See [Backward Compatibility Testing](#backward-compatibility-testing).
- **Schema changes** — covered by expand-contract migrations. See [Data Evolution Safety](#data-evolution-safety).
- **Test coverage and mutation score** — enforced by CI gates.
- **Style and formatting** — enforced by linters and formatters.
- **Performance and scalability speculation** — the blueprint adopts scaling patterns when pain arrives, not before (see [Scaling Guidelines](#scaling-guidelines)). Flag a known bottleneck on a hot path; do not speculate about hypothetical scale.

### What review is not

- A style argument.
- A re-design session. If you would build it differently, say so once, then defer unless it materially harms maintainability.
- A blocker for unrelated cleanup. Open a follow-up issue rather than expanding the PR.

## AI-Assisted Engineering

> *"AI doesn't replace engineering discipline. It amplifies what your architecture already provides. In well-governed systems, it accelerates delivery. In weakly structured systems, it accelerates entropy."*
> — [thePHP.cc](https://thephp.cc/welcome#ai) (Sebastian Bergmann, Stefan Priebsch, Arne Blankerts)

AI is a force multiplier for existing architecture, not a productivity shortcut.

- **Architectural boundaries are guardrails.** Module ownership, stable interfaces, and dependency rules constrain AI-generated code.
- **Quality gates are non-negotiable.** Tests, static analysis, mutation testing, and CI thresholds apply equally to human and AI contributions.
- **Code must remain understandable.** Developers stay accountable — AI output is reviewed and maintained like any other code.
- **AI reinforces discipline.** Faster feedback loops, better tests, clearer code. Backed by architecture and policies that prevent surprises.
