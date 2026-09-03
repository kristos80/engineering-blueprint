# Engineering Standards

An opinionated engineering blueprint for building applications. Language-agnostic, framework-agnostic. These standards apply to any project built on object-oriented, stateless request-response architecture.

## Engineering Goal

The primary goal of this blueprint is to minimize the cognitive context required to reason about correctness. Every architectural decision should reduce the amount of code, state, and interactions a developer must understand before confidently making a change.

Determinism, immutability, explicit contracts, isolated units, and mutation testing are consequences of that objective — not independent rules.

## How to Use This Blueprint

This is a **standalone reference** — a map for engineering decisions across projects. It is not tied to any specific codebase.

**For new projects:** adopt these standards from day one. Reference this blueprint in your project's `CLAUDE.md` to have AI-assisted development follow these rules automatically.

**For existing projects:** adopt selectively. Use the table of contents to find the section relevant to your current decision, and apply what fits.

**For AI-assisted development:** point your AI assistant at this blueprint 

> **Work in progress.** This blueprint is under active end-to-end review. As of 2026-06-30, sections from **Design Principles** through **API Design** are considered settled; sections from **Reliability** onward are pending review and may change.

## Table of Contents

- [Design Principles](#design-principles)
- [Project Structure](#project-structure)
- [Architecture](#architecture)
  - [Layers](#layers)
  - [Where Logic Lives](#where-logic-lives)
  - [Dependency Direction](#dependency-direction)
  - [Dependency Injection](#dependency-injection)
  - [File Conventions](#file-conventions)
  - [Use Case Rules](#use-case-rules)
  - [Controller Conventions](#controller-conventions)
  - [Transaction Boundaries](#transaction-boundaries)
    - [Synchronous (Single Connection)](#synchronous-single-connection)
    - [Async with Connection Pools](#async-with-connection-pools)
  - [State in Long-Lived Processes](#state-in-long-lived-processes)
- [API Design](#api-design)
  - [Response Format](#response-format)
  - [Error Handling](#error-handling)
  - [Input Validation](#input-validation)
  - [Authentication & Authorization](#authentication--authorization)
  - [API Versioning](#api-versioning)
  - [Rate Limiting](#rate-limiting)
- [Reliability](#reliability)
  - [Side Effects](#side-effects)
  - [Event System](#event-system)
  - [State Guards & Idempotency](#state-guards--idempotency)
  - [Concurrency Control](#concurrency-control)
  - [Data Evolution Safety](#data-evolution-safety)
  - [Backward Compatibility Testing](#backward-compatibility-testing)
- [Frontend](#frontend)
  - [Architecture](#architecture-1)
  - [Where Frontend Logic Lives](#where-frontend-logic-lives)
  - [Authentication in Browsers](#authentication-in-browsers)
  - [Asset Injection](#asset-injection)
  - [Per-Page Context](#per-page-context)
  - [One Bundle, Internal Dispatch](#one-bundle-internal-dispatch)
  - [Frontend Anti-Patterns](#frontend-anti-patterns)
- [Read Path Classification](#read-path-classification)
  - [Critical Paths](#critical-paths)
  - [Tolerant Paths](#tolerant-paths)
  - [Why the Split Is Non-Negotiable](#why-the-split-is-non-negotiable)
  - [Rules](#rules)
  - [Read-Path Anti-Patterns](#read-path-anti-patterns)
- [Infrastructure](#infrastructure)
  - [Background Jobs & Queues](#background-jobs--queues)
    - [Step Completion Tracking](#step-completion-tracking)
  - [Database Strategy](#database-strategy)
    - [Horizontal Scaling in Every Direction](#horizontal-scaling-in-every-direction)
  - [Caching](#caching)
  - [Observability](#observability)
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
  - [Contract Maturity](#contract-maturity)
  - [Requirements](#requirements)
  - [Stubs vs Mocks](#stubs-vs-mocks)
  - [Isolation Per Layer](#isolation-per-layer)
  - [Infrastructure Integration Tests](#infrastructure-integration-tests)
  - [Test Structure](#test-structure)
- [Scaling Guidelines](#scaling-guidelines)
- [Anti-Patterns](#anti-patterns)
- [On Frameworks](#on-frameworks)
- [Code Reviews](#code-reviews)
- [AI-Assisted Engineering](#ai-assisted-engineering)

## Design Principles

1. **Stateless services.** Any instance can serve any request. Authoritative state lives in the database; tokens carry identity. Long-lived processes may hold *rebuildable* in-memory state (pools, caches) — never authoritative state. Scales horizontally behind a load balancer. See [State in Long-Lived Processes](#state-in-long-lived-processes).
2. **Immutable data.** Entities, value objects, DTOs, use cases, controllers — all immutable. No setters, no in-place mutation. Behavior is functions that take immutable input and return new immutable output. State transitions produce new instances; they do not mutate existing ones.
3. **Transactional.** Every use case that writes data is a single DB transaction — all or nothing.
4. **Explicit over implicit.** Dependencies are injected, not resolved magically. State is checked, not assumed. Contracts are interfaces, not conventions.
5. **No premature abstraction.** Three similar lines of code are better than a helper nobody asked for. Add structure when pain arrives, not before.

## Project Structure

```
src/
├── Controller/       # HTTP adapters — receive requests, return responses
├── UseCase/          # Application logic — one class per business operation
├── Domain/           # Entities, value objects, domain services, repository interfaces
├── Shared/           # Technical utilities reusable across the codebase — no domain concepts, no I/O
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
- **Domain** contains entities, value objects, domain services, and repository interfaces. Zero dependencies on outer layers.

### Where Logic Lives

Logic placement follows two principles from above: data is immutable, and behavior is decomposed by operation. Entities carry data and enforce invariants but have no mutating methods. State transitions, calculations, and multi-entity coordination live in domain services — **one service per operation**, colocated with the aggregate it operates on. This trades language-enforced invariants for explicit data flow, trivial testability, and consistency with the rest of the blueprint's immutable style.

Getting placement wrong is the most common cause of architectural drift — use cases that bloat into orchestration nightmares, controllers that grow domain logic, services that turn into god-classes.

| Logic type | Lives in | Examples |
|------------|----------|----------|
| Immutable data + invariants enforced in the constructor | **Entity** | `new Booking(...)`, `Booking::createNew(...)`, `Booking::reconstituteFromRow(...)` — no setters, no mutating methods |
| Self-contained immutable concept with validation and equality | **Value Object** | `Money`, `EmailAddress`, `PhoneNumber`, `DateRange` |
| Immutable data carrier with no invariants, shaped for a specific consumer (projection, dashboard, API request/response) | **DTO** | `BookingDashboard`, `UserAnalyticsView`, `CreateBookingRequest` |
| One operation over domain data — state transition, calculation, or multi-entity coordination | **Domain Service** | `BookingConfirmer.confirm(booking)`, `BookingCanceller.cancel(booking, reason)`, `PricingCalculator.calculate(booking, package)`, `AvailabilityFinder.find(professional, range)` |
| Orchestration of one business operation in one transaction | **Use Case** | `CreateBookingUseCase`, `CancelBookingUseCase` |
| Producing immutable entities or DTOs from a data source | **Repository** | `BookingRepository.findById()`, `BookingRepository.save()`, `BookingDashboardRepository.find()`, `UserAnalyticsRepository.findForMonth()` |
| Reusable technical logic — no domain concepts, no I/O | **Shared Service** | `IdGenerator`, `Slugifier`, `Clock`, `RetryPolicy` |
| I/O against external systems | **Infrastructure adapter** (behind an interface) | `RedisCacheAdapter`, `BillingProviderApiClient`, `SqsQueueAdapter` |

**Decision rule.** Ask, in order:

1. Immutable data + construction-time invariants for one concept? → **Entity** or **Value Object** (entity if it has an identity over time; value object if it is defined by its data).
2. Immutable data, no invariants, shaped for a specific consumer (projection, dashboard, API request/response)? → **DTO**.
3. Touches I/O? → **Infrastructure adapter**, behind an interface.
4. Generic technical logic with no domain concepts? → **Shared Service**.
5. One operation over domain data (transition, calculation, coordination)? → **Domain Service** — one service per operation, named for what it does.
6. Single business operation owning a transaction? → **Use Case**.
7. Reads from or writes to a data source? → **Repository** — aggregate repository for the source-of-truth aggregate; read-only repository for any other shape (cross-aggregate joins, dashboards, analytics, derived views).

**One service = one operation.** A domain service does one thing. `BookingManager` with `confirm()`, `cancel()`, `reschedule()` is the wrong shape — split into `BookingConfirmer`, `BookingCanceller`, `BookingRescheduler`. The discipline solves both ends: invariants live in the obvious file, and "what can I do with a `Booking`?" is answered by `ls src/Domain/Booking/`.

For misplacement anti-patterns (mutating entities, god-services, controllers branching on domain state, domain services touching I/O, shared services holding domain concepts), see [Anti-Patterns → Architecture](#anti-patterns).

**Colocation.** Each aggregate gets its own folder under `src/Domain/`, holding the entity and every service that operates on it:

```
src/Domain/
└── Booking/
    ├── Booking.php                    # Immutable entity
    ├── BookingRepositoryInterface.php
    ├── BookingConfirmer.php           # One service per operation
    ├── BookingCanceller.php
    ├── BookingRescheduler.php
    ├── PricingCalculator.php
    └── AvailabilityFinder.php
```

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
- Each use case class implements a corresponding interface (for DI and testing). In languages where `final` classes cannot be mocked by the testing framework (PHP, C#, Kotlin), the interface is also a *testing necessity* — controllers are unit-tested against a stubbed use case interface, and `final` on the implementation is preserved for the runtime safety and compiler-optimization reasons documented in [Design Principles](#design-principles)
- Registered in the container as `Interface → Implementation`
- Pure application logic — no framework imports, no HTTP concepts

### Controller Conventions

- All controllers extend `AbstractController`
- Concrete controllers are final, immutable classes
- Implement a protected `invoke()` method (the base class handles HTTP dispatch)
- Use a shared `jsonResponse(response, data)` method for JSON output
- Route args accessed via request attributes
- Constructor injects use case interface(s)

### Repositories

A **repository** is any class that produces immutable entities or DTOs from a data source, behind an interface. Two flavors on the same axis:

- **Aggregate repository** — owns one aggregate's read+write surface. Partition-scoped, source-of-truth queries. Returns the entity; accepts it on writes.
- **Read-only repository** — shapes DTOs that aren't the aggregate: cross-aggregate joins, dashboards, analytics, derived views. No write methods.

"Aggregate" here covers single-entity cases too. A flat `Booking` loaded from one table is an aggregate of size one; the repository is still the aggregate repository because it owns reads + writes for that entity. The distinction from read-only repositories is about write authority, not entity count.

Value Objects are properties of entities and DTOs, not repository return types. `Money`, `EmailAddress`, and similar concepts surface as typed fields inside the entity or DTO the repository hands back, never as a top-level return.

Same category, same conventions. Naming rule: `{WhatItReturns}Repository`.

```
src/Domain/
└── Booking/
    ├── Booking.php                                # Entity
    ├── BookingRepositoryInterface.php             # Aggregate repo — returns Booking
    ├── BookingDashboardRepositoryInterface.php    # Read-only — returns BookingDashboard
    └── UserAnalyticsRepositoryInterface.php       # Read-only — returns UserAnalytics
```

**Logic placement is practical, not dogmatic.** Aggregations, derivations, conditional shaping, and branching live wherever they read most naturally — in the repository (often: `GROUP BY`, `JOIN`, computed columns, window functions) or in a domain service (often: rules over already-loaded entities). The blueprint does not draw a line between "what SQL can express" and "what application code should express" — those lines turn philosophical fast. Place it where it's clearest. Stubs/mocks unit-test the logic regardless of where it sits (see [Isolation Per Layer](#isolation-per-layer)).

**Backing store is the implementation, not the contract.** A read-only repository can be backed by anything — a join over normalized tables, a projection table, a search index, a read replica, a materialized view. Choice follows [Read Path Classification](#read-path-classification): critical reads against source of truth, tolerant reads against derived stores. The interface stays stable; implementations swap.

**When to split a read out of an aggregate repository.** The return shape isn't the aggregate, OR the data source differs from the aggregate's table, OR the read serves a fundamentally different consumer (dashboard, report, search). Until one of those triggers, the read stays on the aggregate's repository.

**Repository rules:**

- Returns immutable entities (aggregate repository) or DTOs (read-only repository); accepts entities, DTOs, or primitives on writes — no mutable input
- Behind an interface; the implementation reads from a single data source
- No calls to other repositories — cross-aggregate orchestration belongs in the use case or domain service
- No I/O beyond the data source — no HTTP calls, no queue dispatches, no filesystem
- Tested both as unit (stub the data source) and integration (real backend) per [Isolation Per Layer](#isolation-per-layer)

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

**Trade-off worth naming.** The connection holder *is* implicit context, which sits awkwardly next to the blueprint's "explicit over implicit" principle. The alternative — threading a `tx` parameter into every repository method — leaks transaction awareness into every repository signature and breaks the "repositories are transaction-unaware" rule above. The blueprint accepts the implicit context because it is **contained to a single, well-understood component** (the holder) rather than spread across the codebase. If your team prefers the explicit-parameter style, swap the holder for `tx`-passing — the rest of the architecture stands.

**Request-scope is non-negotiable.** Whichever style you pick, the implicit-context version *must* be bound to the request's async execution context — `AsyncLocalStorage` in Node, `context.Context` in Go, `ContextVar` in async Python, request-scoped DI in JVM/Kotlin. A process-wide or module-level holder will leak the active transaction across concurrent in-flight requests, routing one request's queries into another request's transaction. This is the failure mode the explicit-parameter alternative avoids by construction; if the holder route is taken, the async-context binding is what closes the same gap.

### State in Long-Lived Processes

"Stateless" applies to services, not processes. A PHP-FPM worker is recycled per request, so the language enforces process-level statelessness for free. A long-lived async runtime (Node.js, Go, async Python, JVM) does not — the process survives across requests and may legitimately hold rebuildable in-memory state: connection pools, prepared statement caches, compiled regexes, JIT-compiled query plans.

The rule for any in-memory state in a long-lived process:

1. **Rebuildable from authoritative storage** — losing it loses no data, only performance.
2. **Not request-scoped to one instance** — a request must not depend on state held only on the instance it landed on. Any other instance can serve the same request.
3. **Coherent across instances** — if the data can be mutated elsewhere (another instance, another process, the database directly), there must be a defined coherence model.

The third point is the one that bites. Process A holds a cached user list. Process B writes the database. A's copy is now stale. Options, in order of preference:

| Approach | When to use |
|----------|-------------|
| **Don't cache in process** — use a shared cache (Redis) and pay the network hop | Default. Preserves coherence for free; invalidation lives in one place. |
| **TTL-based local cache** | Slow-changing data where bounded staleness is acceptable: feature flags, lookup tables (currencies, countries), config. |
| **Pub/sub invalidation** | Hot data where the network hop matters and staleness is unacceptable. Adds infrastructure (Redis pub/sub, NATS, Kafka). |
| **Event-sourced read models / CQRS** | Heavy machinery; only when simpler options have been outgrown. |

Default to the first option. In-process caching imports a distributed-systems problem (coherence across instances) that a stateless request-response model gives you for free. The cost of a Redis hop is almost always less than the cost of debugging stale-read incidents.

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

Validation is two-tier — split by what the layer has the context to check.

- **Structural validation in the controller.** Types, formats, required fields, length bounds, value ranges, enum membership. Reject malformed HTTP input before it reaches the use case. This is adapter logic — no business knowledge required.
- **Business validation in use cases and domain services.** Uniqueness checks, state preconditions ("can this booking be cancelled?"), authorization beyond identity ("can this user act on this resource?"), and any rule that requires reading current state. Domain invariants enforced in entity constructors (see [Where Logic Lives](#where-logic-lives)) are the innermost layer of this — if an entity refuses to be constructed, the rule cannot be bypassed.
- **Use cases assume structural validity, not business validity.** The controller guarantees the input parses; the use case still checks whether the operation is allowed against current state.
- Return 422 with field-level errors in the `fields` object for structural failures; map business-rule violations to the appropriate domain exception (typically `DomainException` → 409 or `AuthorizationException` → 403).

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

Four concerns, three layers. Do not collapse them into a single "is this user allowed" check.

- **Authentication** — middleware. Extracts the JWT from the `Authorization` header, verifies it, and attaches the principal (user ID, role, tenant scope) to the request. 401 on missing or invalid token. Controllers access the principal via `request.getAttribute("auth")`. Use cases receive a user ID, never a token.
- **Coarse authorization (role gate)** — middleware. Checks the principal's role against the route before the controller runs. 403 on failure. Controllers do not recheck — middleware already did.
- **Fine authorization (resource-bound)** — use case. "Can this principal act on this specific resource?" Requires loaded state, so it lives where state is loaded. Raises `AuthorizationException` → 403. This is what the validation rules call "authorization beyond identity."
- **Tenant scoping** — repository. The tenant ID is read from the authenticated principal (the principal attached by middleware) and applied as a `WHERE` clause on every query. Never trust a tenant ID from path, body, or query string. This prevents IDOR by construction, not by policy. The Scale section calls this same column the partition key — the discipline is identical; only the lens differs (authorization here, shardability there). The rule applies even when the database is not sharded.

```
Request → AuthMiddleware (principal + role gate) → Controller → UseCase (fine authorization) → Repository (tenant-scoped query)
```

- Authentication and authorization span the edge **and** the use case — split deliberately. The edge handles identity and role; the use case handles resource-bound access.
- 401 ≠ 403. Authentication failure (no/bad token) and authorization failure (logged-in but disallowed) are distinct exceptions and distinct status codes.
- Log the principal ID and the resource on every authorization decision; the decision itself is not a secret.

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

### Side Effects

When a use case has work beyond its core operation — sending email, recording analytics, syncing a calendar, charging a card, dispatching SMS, coordinating multiple writes across systems — three mechanisms cover everything:

| Situation | Mechanism |
|-----------|-----------|
| Must succeed for the operation to succeed. Inside the transaction: **DB reads/writes only** — external calls cannot be rolled back. If an external call must complete in this request, the operation is multi-step (commit DB, then external call as a queued job) | **Synchronous call** |
| In-request, fire-and-forget where loss is acceptable (analytics ping, in-process audit log, cache warm). One action, OK if it fails | **In-process event** (see [Event System](#event-system)) |
| Must eventually succeed. Anything else — external calls, long-running work, multi-step coordination across systems | **Queued job** (see [Background Jobs & Queues](#background-jobs--queues)) |

**One async mechanism.** Everything async goes through the queue. A payload, tagged with a `type` and `version`, is enqueued. A **handler** — code that knows what to do with that `(type, version)` pair — picks it up, performs the work, and either succeeds or retries up to a threshold. The blueprint does *not* provide a separate "flow execution" mechanism for multi-step jobs. Multi-step coordination, step skipping on retry, and progress tracking are responsibilities of the handler, expressed in its own domain logic — typically by writing idempotently against domain tables that naturally record progress (the product row, the search-engine document, a `notifications` row). This is a deliberate choice: generic flow/workflow frameworks trade flexibility for complexity that most apps do not need, which is exactly the trade [the blueprint opposes](#on-frameworks).

**Transactional outbox — enqueue inside the transaction.** "Commit, then enqueue" has a silent failure mode: the database commits, the process dies (or the network blips, or the queue write times out) *before* the enqueue lands, and the side effect is permanently lost. Because the queue is a database-backed `jobs` table (see [Background Jobs & Queues](#background-jobs--queues)), the fix is mechanical: insert the job row **inside the same transaction** as the business write. Either both commit, or neither does — no commit-to-enqueue gap exists.

```
transaction.run(() =>
    booking = bookingRepository.create(...)              // domain write
    jobRepository.enqueue("booking.send_confirmation",   // outbox insert
                          version=1,
                          payload={ booking_id: booking.id })
)
// Worker picks up the row after commit — never before, because it isn't visible yet
```

This is the **transactional outbox pattern**: the `jobs` table is the outbox, the worker is the publisher, and the queue contract is preserved without an external message broker. If the queue ever migrates off the database (Kafka, SQS, etc.), the same table becomes a true outbox that a relay process drains into the external broker — same pattern, different topology.

**Idempotency: recommended, not forced.** When a handler has steps whose effects must not be duplicated on retry, it implements idempotency — usually by checking domain state ("does this record already exist?", "have we already notified for this?") before acting. Not every handler needs it; trivial jobs that run once may not. The handler decides.

**Retry threshold.** Every job has a maximum attempt count after which it stops retrying and surfaces as failed for operator attention. Where the threshold lives — in the jobs table, in the handler — is an implementation choice; both work.

**Anti-patterns:**

- **Using events for things that must succeed.** "Send the confirmation email" via an event silently loses messages if the listener throws. Use a queued job.
- **Using queues for things that must happen in this request.** Charging a card via a queued job means the caller gets a 200 OK before payment confirms. Use a synchronous call (after commit, with idempotency on the external side).
- **External calls inside a transaction.** Email, SMS, webhooks, payments — none of these can be rolled back by `ROLLBACK`. They happen after commit, not inside it.
- **Building a generic flow framework on top of the queue.** A workflow engine is the kind of generic abstraction the blueprint opposes. If steps need coordination, the handler does it in code against domain tables — not via a separate flow-execution table.

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

State guards on entities enforce valid transitions. The handler records which steps completed — typically as idempotent writes against domain tables (the row exists, the document is indexed, the notification was logged). Together they prevent both "step was skipped" and "step ran out of order."

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

This matters for any retried job — a worker may re-execute a step that already completed but whose completion did not persist. Re-runs must be safe.

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

**Key lifetime is bound to data lifetime, not to a clock.** An idempotency key exists to recognize a retry against the current state; as long as the data the key protects still exists, the key must too. Time-based pruning (a 7-day or 30-day window, an "old keys are probably new operations" policy) silently converts retries into duplicate writes — the exact bug idempotency exists to prevent. The client sent the same key, which is a contractual assertion that this is the same operation; the system does not get to reinterpret that assertion based on age. Performance on growing idempotency tables is solved with partitioning by `created_at`, narrow covering indexes on `(key, status)`, and hot/cold splits if a single table genuinely outgrows a node — never with deletion. Keys are purged only when the underlying data is purged (tenant offboarding, GDPR erasure, hard archive of a closed account); the lifecycles move together because they are part of the same authoritative record.

### Concurrency Control

State guards and idempotency handle "the same operation tries to run twice." Concurrency control handles "two operations try to commit conflicting changes." The mechanism depends on operation type, not scenario:

| Operation | Concurrency mechanism | Why |
|-----------|----------------------|-----|
| **Create** — insert a unique-once row (payment, booking, user) | Idempotency key (in the use case) + **unique constraint** (in the DB) | The record is created once and rarely mutated afterwards. Idempotency stops the duplicate request before it reaches the DB; the constraint catches the race that slips past. No locking needed — the constraint serializes the inserts. |
| **Update — critical** — financial state transitions, scarce-resource allocation, anything where retry is hard to recover from | **Pessimistic locking** — `SELECT ... FOR UPDATE` | First writer wins, second waits and sees the new state. Deterministic. No retry semantics to get wrong. |
| **Update — non-critical** — post edits, profile updates, settings | **Optimistic locking** — version column with bounded retry | Same first-wins semantics, cheaper at low contention. Acceptable because a "please retry" outcome is recoverable for user-editable resources. |

**Universal rule for updates:** first writer wins; second fails or retries. Pessimistic and optimistic locking produce the same outcome — pessimistic is deterministic, optimistic is cheaper but spurious-failure-prone. Pick by criticality, not by taste.

**Universal rule for creates:** idempotency + unique constraint. No locking. Payments, bookings, and other "create-once" records flow through this pair every time.

The blueprint's stance: **prefer database constraints and locks over application-level checks**. Constraints fail closed; application checks have race windows. Application checks may run alongside for friendlier error messages but are never the source of truth.

#### Unique constraints — the create-side guard

The strongest guard for create operations: the database refuses to insert a duplicate. No race window, no application logic to forget.

```sql
ALTER TABLE bookings
    ADD CONSTRAINT unique_slot UNIQUE (professional_id, scheduled_at);

ALTER TABLE users
    ADD CONSTRAINT unique_email UNIQUE (email);
```

The repository catches the constraint violation by name and translates it to a typed domain exception:

```
try
    bookingRepository.save(booking)
catch UniqueConstraintViolationException(constraint: "unique_slot")
    throw SlotAlreadyTakenException(booking.scheduledAt)
```

- One constraint = one named exception = one HTTP response shape (typically 409 Conflict).
- Catch only the specific constraint by name; rethrow others — never swallow a generic constraint violation.
- Pair with idempotency in the use case (see [State Guards & Idempotency](#state-guards--idempotency)). The idempotency key handles the duplicate request; the constraint handles the rare race that slips past.

#### Pessimistic locking — the default update-side guard

`SELECT ... FOR UPDATE` inside a transaction. Holds a row-level lock until commit or rollback. Other transactions trying to acquire the same lock wait.

```
return transaction.run(() =>
    // Lock the row for the duration of this transaction
    booking = bookingRepository.findByIdForUpdate(id)
    confirmed = bookingConfirmer.confirm(booking)
    bookingRepository.save(confirmed)
    return confirmed
)
```

- **Default for updates.** Critical operations (financial state transitions, scarce-resource allocation) should use this. The cost of holding a short lock is almost always less than the cost of getting the retry logic wrong.
- Always inside a transaction with a short, clear scope. Long-held locks introduce deadlock risk.
- Expose locking intent at the repository interface (`findByIdForUpdate`) — never as a hidden side effect of a regular `findById`.

#### Optimistic locking — the cheap update-side guard

Only for non-critical, user-editable resources where a "please retry" outcome is acceptable. A `version` column on the row; reads include the version, writes require it to match. If another writer incremented the version in between, the update affects zero rows.

```
// bookings table has a `version INT NOT NULL DEFAULT 0` column
booking = bookingRepository.findById(id)

// ... domain transformation ...
edited = bookingEditor.applyChanges(booking, changes)

affected = connection.execute(
    "UPDATE bookings SET notes = ?, version = version + 1
     WHERE id = ? AND version = ?",
    [edited.notes, edited.id, booking.version]
)

if affected == 0
    throw OptimisticLockException()
```

- **Not for financial flows, slot allocation, or anything where conflict recovery is hard.** Use pessimistic locking instead.
- The use case owns retry policy: typically 1–3 attempts with brief backoff, then surface a 409 Conflict. Unbounded retry is an outage waiting to happen.
- The repository raises a typed exception on zero affected rows; it does not retry on its own.

#### Why constraints and locks beat application checks

The naive pattern has a race window:

```
// Two concurrent requests can both pass this check before either inserts
if bookingRepository.slotIsTaken(slot)
    throw SlotAlreadyTakenException()
bookingRepository.create(booking)
```

Both requests pass the check in the same instant, both reach `create`, and both succeed. The window is small but real, and at any non-trivial scale it will happen. A unique constraint eliminates the window — the database serializes the two inserts and one fails. The same logic applies to updates: a `findById` followed by a conditional `save` without locking or a version check has the same race window as the create example above.

**Rule:** if a uniqueness or sequencing invariant exists in the domain, encode it in the database — as a constraint (for creates) or a lock (for updates). Application checks may run alongside for friendlier error messages, but they are never the source of truth.

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

**Schema execution safety on large tables.** Expand-contract describes the *shape* of safe evolution; it does not describe how each step actually runs. On large tables, a single `ALTER TABLE` or non-concurrent `CREATE INDEX` can take an exclusive lock for seconds-to-minutes and stall every write during that window — turning a "safe" expand step into a production outage. Above a threshold (a common starting point is ~1M rows, lower if the table is on a hot write path), schema changes must use lock-light primitives instead:

- **PostgreSQL** — `CREATE INDEX CONCURRENTLY`, `ALTER TABLE ... ADD COLUMN` without a non-null default on recent versions, `SET STATISTICS`, `VALIDATE CONSTRAINT` as a separate step from `ADD CONSTRAINT NOT VALID`
- **MySQL 8+** — online DDL (`ALGORITHM=INPLACE, LOCK=NONE`) where the operation supports it; verify per-operation, since some still take a metadata lock
- **External tools** — `gh-ost`, `pt-online-schema-change` for operations the database cannot do online natively; they copy the table in the background and swap atomically

The migration tool running in CI must know which strategy applies, and the migration itself should fail-fast in CI if it would attempt a locking operation against a table above the threshold. "It worked on staging" is not a guarantee when the production table is 100× larger.

**Declarative diff tools above the threshold: generate, don't auto-apply.** Schema-driven migration tools that diff a declared schema against a live database are convenient for small tables but dangerous on large or sharded ones. A diff that looks benign at the schema level can compile to destructive SQL (`DROP CONSTRAINT; ADD CONSTRAINT`, table rewrite, full-table `ALTER`), which then runs against every shard in turn. Above the row/criticality threshold, the rule is: the tool **generates the SQL patch; a human reviews and executes it** using the lock-light primitives above. Auto-apply mode is fine for development and small tables; it is not safe as a deployment step against production data of meaningful size.

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

## Frontend

Server-side rendered, multi-page. JavaScript is progressive enhancement, not the whole app.

### Architecture

- **Server-side rendering (SSR), multi-page application (MPA).** Every URL is a real server route that returns fully-rendered HTML. Navigation reloads the page.
- **No SPA.** No client-side router. No history-API manipulation for view state. No route guards in JS.
- **JS framework as view-layer only.** Data binding, AJAX, templates. Not a router. Not a global state store. Not a build-time application shell.
- **Framework choice is per-project**, not blueprint-mandated. Any framework that can operate as view-layer sprinkles (Alpine, Vue in mount-mode, plain reactive libraries, or a heavier framework used as a component library) fits. Frameworks whose value proposition *is* SPA routing and global state stop paying rent under these rules.

### Where Frontend Logic Lives

| Concern | Lives on the | Rationale |
|---|---|---|
| URL routing | server | Server owns every URL; the browser reloads on navigation |
| Auth session | server (JWT in an HttpOnly cookie) | Browser attaches on every request; JS never touches the token |
| Persistent state | server (round-trip on write, re-render on read) | State that survives a reload lives in the database |
| Per-page data hydration | server (context blob injected into the HTML) | The backend already knows what the page needs; embedding is one fewer round-trip |
| DOM data binding | client (single JS bundle; internal scenario dispatch) | View-layer only. One framework instance, one bundle, one asset to cache. |
| AJAX to internal endpoints | client (JS framework) | Progressive enhancement — live updates, form actions, incremental UI |
| Client-rendered templates | client (JS framework) | For content that must change without a page reload |
| Page-scoped ephemeral state | client (framework instance memory) | Dies with the page — dropdown open/closed, filter state before submit |

### Authentication in Browsers

- **JWT in an `HttpOnly`, `Secure`, `SameSite=Lax` cookie.** Set on login, cleared on logout. Browser attaches on every same-origin request automatically. JS never reads it.
- **Programmatic clients** (SDKs, headless integrations, mobile apps) use `Authorization: Bearer <token>` — same JWT, different transport.
- **Middleware verifies both paths identically.** Cookie present OR Authorization header present → same principal extraction.
- **CSRF.** Same-origin + `SameSite=Lax` is sufficient for same-origin form POSTs. When mixing origins, embed a CSRF token in the context blob (see below) and require it on state-changing endpoints.

### Asset Injection

- **Backend emits every page** with references to the current JS bundle(s), versioned so the browser cannot serve a stale copy.
- **Versioning is either a content hash in the filename** (`/assets/app.abc123.js`) **or a query-string version tag** (`/assets/app.js?v=abc123`). Both are acceptable; use whichever your framework's build tooling already produces.
- **The version identifier** (`abc123` above) can come from any deterministic source that changes when the bundle changes: content hash, build timestamp, git SHA, incrementing build number.
- **The backend reads a manifest** (`assets.json` or equivalent) at render time that maps logical names to the current version identifier. The manifest is the source of truth; templates never hardcode paths.
- **Cache headers.** On hashed-filename assets: `Cache-Control: public, immutable, max-age=31536000` — safe because the URL itself changes on rebuild. On query-string-versioned assets: `Cache-Control: public, max-age=31536000` — the browser sees the changed query string as a different resource, same effect.

### Per-Page Context

- **The backend injects one global blob** into the rendered HTML, before any script tags load:

  ```html
  <script>window.__APP__ = {
    context:  { page: "settings.billing", account_id: 42 },
    csrf:     "…",
    flags:    { … },
    api_base: "/api/v1"
  };</script>
  ```

- **`context.page` identifies which page this is.** The boot code reads it, dispatches to the corresponding component, mounts it.
- **`context` carries stable-per-URL state only.** Anything that could change between two loads of the same URL — cart contents, notification counts, unread messages, live inventory, "last seen X" — is **not** in the blob. It is fetched by an AJAX call after boot.
  - **Rule of thumb:** if a cache layer (CDN, reverse proxy, browser) could serve the same rendered HTML to two visitors or to the same visitor twice with different expected values, that data does not belong in the blob. An extra request beats stale data.
  - **Blob-safe:** `page`, `account_id`, `csrf`, `api_base`, `flags`, feature-flag values that don't move between deploys, static labels.
  - **Blob-unsafe (fetch on load):** `cart`, `notifications_unread`, `wallet_balance`, `live_stock_levels`, `session_start`, `last_activity_at`.
- **No client-side `/me` or `/config` calls for stable-per-URL data.** The backend already knew — inject it.
- **JS never derives routing state from `window.location`.** The context blob is authoritative.
- **Environment-specific configuration** (`api_base`, feature flags, public tokens that don't rotate per-session) goes through the blob, never through hardcoded module constants.

### One Bundle, Internal Dispatch

- **One JS bundle for the whole application**, not one per page. The framework's runtime cost pays off across every page; the browser caches the bundle once.
- **A tiny internal dispatcher** — a switch on `__APP__.context.page` — maps the scenario name to the component that owns it and mounts it. The dispatcher is not a router in the SPA sense (it does not touch URLs or history). It is a `page → component` lookup that runs once at boot.
- **Per-scenario code lives as components inside the bundle**, split by file or module for code organization, but shipped and loaded together. Lazy code-splitting is fine when the bundle grows large enough to matter; it is a size optimization, not an architectural rule.
- **The dispatcher never falls back to DOM detection.** It reads the context blob or does nothing.

### Frontend Anti-Patterns

- **SPA routing.** No `router-link`, no history-API for view state, no route guards in JS. If URL routing is happening in the browser, the app has drifted off the blueprint.
- **Client-side state stores** (Redux, Pinia, Zustand, MobX, etc.) as authoritative state. Ephemeral view state is fine; persistent state belongs on the server.
- **`localStorage` or `sessionStorage` for auth tokens.** Cookies with `HttpOnly` beat every JS-accessible storage on XSS resistance. If JS can read it, an injected script can too.
- **Fetching page config on load** for stable-per-URL data (`/me`, `/config`, `/features` from boot code). The backend rendered the page — it already knew. Inject it in the context blob. Fetching mutable data on load (cart, notifications) is expected and correct.
- **Deriving the current route from `window.location`.** The context blob names the page; JS never guesses.
- **Uncached assets** (`app.js` with no version tag at all — browser and CDN serve whatever they cached last). Version via content hash or query string; either works.
- **Boot code that branches on DOM presence** (checking `document.querySelector('.cart-page')` to decide what to run). The context blob names the scenario; JS never guesses from the DOM. This is different from having one main bundle — one bundle with an authoritative dispatcher is the pattern; one bundle that scans the DOM to figure out where it is, is not.
- **Mutable data in the context blob.** Cart contents, unread counts, wallet balances, or any value that could differ between two loads of the same URL. Extra AJAX beats stale data.
- **SSR-then-hydrate frameworks that still ship the full application shell to the client.** The blueprint's SSR is real SSR — HTML from the server, JS as enhancement. Frameworks whose "SSR mode" still boots a client router are outside this posture.

## Read Path Classification

Every read in the system belongs to one of two categories. Decide which before writing the query, and place the data accordingly. Mixing the two in the same store is the root cause of most scaling pain.

This principle applies to every storage class — databases, caches, search indexes, blob stores, message-derived projections. The reason it shows up most often as a *database* discipline is that databases are the one place teams routinely violate it; for every other storage class the derived-store nature is already obvious from the tool's name.

### Critical Paths

Reads that block a user-facing action — anything in the request path of a paying user.

- Read from the **source-of-truth store**, scoped by the partition key.
- Tight latency budget. Failure is user-visible; the store must be highly available.
- **Never depend on a derived store being up, fresh, or even reachable.** If a projection, cache, or search index is down, the critical path degrades cleanly or fails its own feature only — it does not take down login, writes, or unrelated reads.

### Tolerant Paths

Reads that produce a view, report, recommendation, aggregate, or any computation the user can wait for, retry, or live without.

- Read from a **derived store** — an asynchronous projection built from the source (CDC, event stream, scheduled batch).
- Loose latency budget. Seconds, minutes, or hours, chosen per use case.
- Failure means "stale" or "temporarily unavailable" — never "site down."
- **Must be safe to wipe and rebuild from the source at any time.** The source is authoritative; the derived store is disposable.

### Why the Split Is Non-Negotiable

The two categories have opposite scaling profiles. Critical paths scale by sharding the source — more tenants, more shards, flat latency. Tolerant paths scale by adding read capacity to the derived store, or by moving the projection to an engine tuned for the query shape (columnar, OLAP, full-text, vector). Serving both from one store forces a compromise: either critical paths slow down under analytical load, or analytical queries get throttled to protect them. Separating the two is what makes both horizontally scalable without coordination.

### Rules

- Classify the path before writing the query. If the answer can be seconds (or minutes) stale without business impact, it is tolerant — route it through a derived store, even if "the source could handle it today."
- Cross-tenant reads are tolerant by default. The source is partitioned by tenant; any read that spans tenants belongs in a derived store.
- The projection from source to derived store is asynchronous and idempotent. Replays must converge to the same result.
- The derived store has no authoritative data of its own. Anything that originates outside the source (a learned model, an admin annotation) must be persisted back to the source before anything depends on it.
- Critical-path code must handle "derived store unavailable" as an expected condition, not an exception.

### Read-Path Anti-Patterns

- A user-facing endpoint that runs `JOIN ... GROUP BY` across tenant shards on every request.
- A critical path that fails when a cache, search index, or analytical store is unreachable.
- A derived store that cannot be rebuilt — because the source no longer has the data, or because the rebuild logic exists only in a one-off script.
- "Real-time" applied to a path that is tolerant in business terms. If stale is acceptable, treat it as tolerant; if not, the path is critical and must hit the source.
- Authoritative state living in the derived store. The moment a projection is the only place a fact exists, the projection has stopped being disposable.

## Infrastructure

### Background Jobs & Queues

Database-backed queue. No external queue dependency until load requires it. This is the single async mechanism for the whole app — see [Side Effects](#side-effects) for the framing.

```
jobs
├── id
├── type            -- "send_sms", "product_sync", "professional_onboarding", etc.
├── version         -- INTEGER, per-type. Bumped when the handler contract changes.
├── payload         -- JSON
├── status          -- pending | processing | completed | failed
├── attempts        -- retry count
├── max_attempts    -- per job type
├── next_retry_at   -- exponential backoff
├── created_at
└── updated_at
```

**Payload + handler.** A job is a `(type, version)` pair plus a `payload`. The dispatcher routes by `(type, version)` to a **handler** — the code that performs the work for that contract. Multi-step coordination, step skipping on retry, and progress tracking are the handler's responsibility, expressed in its own domain logic. The blueprint does not provide a generic flow/workflow framework — that flexibility-for-complexity trade is exactly what [the blueprint opposes](#on-frameworks).

**Worker claim pattern.** Workers claim jobs with row-level locking that other workers skip:

```sql
SELECT * FROM jobs
WHERE status = 'pending'
AND next_retry_at <= NOW()
FOR UPDATE SKIP LOCKED
LIMIT 1;
```

- `FOR UPDATE` locks the row — no other worker can grab it.
- `SKIP LOCKED` — other workers don't wait, they grab the next available row.
- Event listeners can dispatch to the queue for async processing.
- Failed jobs retry with exponential backoff (e.g., 1min, 5min, 30min).
- After `max_attempts`, the job is marked failed — surface in admin/monitoring for operator attention.

**Idempotency: recommended, not forced.** When a handler has steps whose effects must not be duplicated on retry, it implements idempotency — usually by checking domain state ("does this record exist?", "have we already notified?") before acting. The handler decides what needs guarding; trivial jobs that run once may not need it.

#### Step Completion Tracking

When a handler has multiple steps that must be skippable on retry, two approaches work.

**Default: domain-table tracking.** Check domain state before each step — *the product row exists*, *the search-engine document is there*, *a row in the existing `notifications` table records the send*. Simplest when the domain naturally records progress. No extra tables, no extra abstraction.

**Recommended pattern when the domain does not express progress:** a thin step-completion helper table.

```
job_step_completions
├── id
├── job_id           -- FK to jobs.id, ON DELETE CASCADE
├── step_name        -- "upsert_product", "index_in_search", "notify_owner"
├── completed_at
└── UNIQUE (job_id, step_name)
```

A small helper exposes two operations to handlers:

```
interface JobStepTracker
    function markCompleted(jobId: Int, stepName: String): void
    function isCompleted(jobId: Int, stepName: String): bool
```

Handlers gate their own steps:

```
class ProductSyncHandler
    function handle(job: Job): void
        if not steps.isCompleted(job.id, "upsert_product")
            productRepository.upsert(job.payload["product"])
            steps.markCompleted(job.id, "upsert_product")

        if not steps.isCompleted(job.id, "index_in_search")
            searchEngine.upsert(productId, ...)
            steps.markCompleted(job.id, "index_in_search")

        if shouldNotify(job.payload) and not steps.isCompleted(job.id, "notify_owner")
            notifier.notify(...)
            steps.markCompleted(job.id, "notify_owner")
```

**When to reach for the helper table:**

- A step has no natural domain footprint (e.g., a one-off external API call that the domain does not otherwise log).
- Cross-handler ops visibility matters — `SELECT job_id FROM job_step_completions WHERE step_name = 'notify_owner'` answers "where did jobs stall?" in one place.
- The team wants a consistent shape for step tracking across handlers.

**When NOT to use it:**

- The domain already records each step's completion. Parallel tracking is two sources of truth; they will drift.
- Single-step jobs with no internal checkpoints — overkill.

**Rules:**

- `UNIQUE (job_id, step_name)` is load-bearing. `markCompleted` is implemented as `INSERT ... ON CONFLICT DO NOTHING` so double-calls are harmless.
- Step **names**, not numbers — `"notify_owner"` is self-documenting in ops queries; `step_number = 3` requires reading handler code.
- `ON DELETE CASCADE` on `job_id` — when a job row is deleted, its step records go with it.
- This is *not* a workflow engine. No step ordering, no DAG resolution, no automatic resumption, no per-step status, no heartbeat. The handler still owns the order and the conditions; the table is just a memory aid the handler queries.

**Versioning.** `version` lives as its own column alongside `type`, not inside the payload JSON. The dispatcher routes by `(type, version)` to the handler matching that pair. When a handler contract changes, bump the version and add the new handler; the old handler stays in code until no jobs of the old version remain. **In-flight payloads are never migrated** — they complete under the contract they were enqueued under; migrating live work in place is the kind of "clever" that produces 3 AM incidents.

The column makes lifecycle queries trivial:

```sql
-- Safe to delete v1 handler code?
SELECT COUNT(*) FROM jobs
WHERE type = 'product_sync' AND version = 1
AND status IN ('pending', 'processing');
```

Index `(type, version)` for these operational queries — but do not expect it to speed up workers. The worker hot path is `WHERE status = 'pending' AND next_retry_at <= NOW() FOR UPDATE SKIP LOCKED`, which uses its own index. Keep the two index purposes separate.

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

#### Horizontal Scaling in Every Direction

A real system does not grow along a single axis. The database tier must have a horizontal answer for every direction the system might grow in, and each answer depends on the same small set of early decisions.

**The directions:**

1. **Tenants.** More customers, accounts, or orgs.
2. **Data per tenant.** A single tenant accumulates millions or billions of rows.
3. **Write throughput.** Ingest rate climbs with usage.
4. **Read throughput.** Queries per second climb with traffic.
5. **Connection count.** More application instances opening more sockets.
6. **Geography.** Users spread across regions; latency starts to matter.
7. **Query shapes.** The same data is asked very different questions — point lookups, aggregates, full-text, similarity, graph traversal.

Vertical scaling answers none of these for long. A single machine has fixed CPU, RAM, IOPS, and connection caps. The horizontal answers below exist for all seven, but they all depend on two earlier decisions.

**The two decisions that unlock everything:**

1. **Partition key, chosen once.** Every source-of-truth row carries a partition key tied to the natural unit of isolation — typically the tenant. Every write specifies it; every read filters by it; no transaction crosses it. With this in place, sharding is mechanical: split the shard map, route by key, done. Without it, sharding is a multi-quarter rewrite of every query.
2. **No authoritative state outside the partitioned source.** Caches, indexes, projections, warehouses — all derived, all rebuildable. Anything that originates outside the source (a learned model, an admin annotation) is written back into the partitioned source before anything depends on it. See [Read Path Classification](#read-path-classification).

These two decisions are the precondition for every horizontal answer below. They cost nothing on a single database today and cost everything to retrofit later.

**Horizontal answers, by direction:**

| Direction | Answer | Precondition |
|---|---|---|
| **Tenants** | Shard the source by partition key. Add shards as tenant count grows; rebalance by moving partitions, not rows. | Partition key on every row and in every query. |
| **Data per tenant** | Sub-shard inside the tenant by time, hash, or a natural sub-key. Archive cold partitions to cheaper storage. | Time or sub-key column present from day one; no queries that scan the whole tenant. |
| **Write throughput** | Append-only tables where possible; absorb bursts in a queue; fan writes across shards by partition key. | Idempotent writes; no global sequences; no cross-partition transactions. |
| **Read throughput** | Read replicas of source shards (still partition-scoped) for critical reads. Derived stores for tolerant reads, scaled independently. | Reads classified as critical or tolerant; application tolerates replica lag on the critical path or routes to primary when it cannot. |
| **Connection count** | Connection pooling and multiplexing in front of every shard. Application instances stay stateless so any instance can talk to any shard. | Stateless services; pool sized per shard, not per app instance. |
| **Geography** | Pin each partition to a home region. Replicate read-only copies into other regions for latency. Global data lives in its own globally-keyed store with explicit cross-region semantics. | Partition is the unit of regional placement; no cross-region transactions; eventual consistency accepted for read replicas. |
| **Query shapes** | One source of truth, many derived stores, each tuned for its query shape — columnar for analytics, inverted index for search, vector for similarity, graph for traversal. Each derived store is rebuildable from the source. | Read path classification; tolerant paths never read from the source shards. |

Every row reduces to the same pattern: **partition the source, replicate per workload, derive per query shape.**

**Why this works:**

- The source stays small per shard. Adding tenants adds shards, not rows-per-shard. Latency stays flat as the system grows.
- Each workload scales independently. Critical-path reads scale by adding replicas. Tolerant reads scale by adding derived-store capacity. Writes scale by adding shards. None of the three competes for the same machine.
- Failure is localized. A shard outage takes out one slice of tenants, not the platform. A derived store outage degrades a feature, not the product.
- Migrations stay tractable. Schema changes apply per shard, in parallel, with no cross-shard coordination. A bad migration can be paused after one shard and rolled back before it reaches the rest.
- The cost curve is linear. Doubling tenants doubles shards. Doubling query types adds one derived store. There is no point at which the architecture has to be thrown away and rewritten.

**Rules:**

- Pick the partition key before writing the first table. Treat it as architectural, not a column.
- Every source-of-truth query filters by the partition key. No exceptions on the critical path.
- Cross-partition operations happen in the application layer, asynchronously, and only for tolerant paths.
- Schema changes are designed to apply per shard. No migration assumes a single global database.
- New query shapes get a new derived store, not a new index on the source. The source's job is to be small, fast, and write-optimized.

**Anti-patterns:**

- A "shared" table that is not partition-scoped (settings, lookups, synonyms) and grows with tenant count. If it has to exist globally, it lives in a separate globally-keyed store with its own scaling story — not in the tenant shards.
- A read replica used for cross-tenant analytics. Replicas exist to scale critical-path reads on the same partition; cross-tenant aggregation belongs in a derived store.
- A globally unique auto-increment sequence used as a primary key in a partitioned table. It silently couples shards and blocks rebalancing.
- A schema migration that requires downtime because it cannot run shard-by-shard.
- "We will shard when we need to." If the partition key is not on every row and in every query today, you cannot shard tomorrow without rewriting the application.

### Caching

- **Cache reads, not writes.** Cache is a performance optimization, never a source of truth.
- **Start without a cache layer.** Add an external cache when actual load demands it.
- **Invalidate explicitly on write** — not time-based TTL (stale data is worse than slow data for most operations).
- **Cache key includes a version** for safe deploys: `v3:professional:slug:glamour-by-sofia`
- **Never cache user-specific data in shared caches** without proper key scoping.
- **Stampede protection is an escalation, not a default.** On extremely hot reads, naive invalidation can cause many concurrent requests to miss the cache at the same instant and slam the source together. Fan-in protection (request coalescing/singleflight, stale-while-revalidate, lock-on-rebuild, or write-through on the specific hot key) is the escalation — applied per workload, not globally. Most caches never need it; the ones that do are obvious from their load profile.

### Observability

> Unlike the rest of this blueprint, this section is a recommendation based on industry consensus rather than rules forged in production incidents. Adopt as a starting point; revise as your team's operational experience grows. Specific patterns depend heavily on the observability stack you choose (Datadog, Honeycomb, Prometheus + Grafana, OpenTelemetry, etc.) — the framing below stays tool-agnostic.

**Three pillars, three different questions.** Production observability is usually framed around three complementary signals. They are not interchangeable; each answers a question the others cannot.

- **Logs** — discrete events with full context. "What exactly happened at this moment, and what did the request look like at the time?"
- **Metrics** — numeric time-series aggregated across many events. "What is the rate, count, latency distribution, or saturation over time?"
- **Traces** — causal chains across components and services. "Which path did this request take, and where did the time go?"

A common rule of thumb: when something breaks, metrics tell you *that* it broke and roughly *where*; traces narrow it to a specific call; logs give the exact context at the failing step.

**Logs.** Structured (JSON) lines, one per discrete event. Machine-parseable, greppable, aggregatable.

```json
{
  "level": "error",
  "message": "Payment charge failed",
  "context": {
    "order_id": "uuid",
    "provider": "billing_provider",
    "error_code": "card_declined",
    "correlation_id": "req-7f3a..."
  },
  "timestamp": "2026-03-06T12:00:00Z"
}
```

Typical things worth logging: incoming requests (method, path, status, duration), outgoing external calls (provider, duration, outcome), errors and exceptions with their context, and business events (booking created, payment processed) as a durable audit trail. Typical things to avoid: passwords, tokens, API keys, card numbers, full request/response bodies in production, personal data beyond what's needed to debug. Log levels follow the usual ladder — `error` for bugs and failures, `warning` for degraded service that recovered, `info` for business events, `debug` for development.

A common pitfall is emitting metrics as log lines (`logger.info("requests_processed=42")`). The metrics pipeline exists to aggregate; log lines are not designed for that.

**Metrics.** Numeric measurements emitted over time and aggregated by a dedicated pipeline. Three primitives cover most needs:

- **Counter** — monotonically increasing total (requests served, errors raised, jobs processed)
- **Gauge** — point-in-time value that can go up or down (queue depth, active connections, in-flight jobs)
- **Histogram** — distribution of values (request latency, payload size, job duration)

Worth instrumenting from day one: request rate, request latency (as a histogram, not just an average), error rate, queue depth and wait time, external-call latency and failure rate, and a small set of business metrics (bookings/minute, payment success rate). Two widely-cited starting checklists are **RED** (Rate, Errors, Duration — for services) and **USE** (Utilization, Saturation, Errors — for resources). Either gives a defensible baseline.

Most metrics pipelines support labels/tags on each measurement (e.g., `http_requests_total{method="POST", route="/booking", status="500"}`). Keep label cardinality bounded — a label whose value is a user ID, request ID, or anything similarly unbounded explodes the time-series count and is the most common metric-pipeline footgun.

**Traces.** A trace captures the causal chain of a single request through the system, broken into spans (one per logical step — controller, use case, repository call, external HTTP call). Each span records its duration, parent span, and a handful of attributes. Together they answer "where did the latency come from?" and "which downstream did the failure originate in?".

Tracing pays off most once requests cross more than one process — service-to-service calls, async job hand-offs, third-party APIs. Within a single process it overlaps with what a good profile would tell you, but the propagation discipline is the same and worth establishing early.

OpenTelemetry has become the de facto vendor-neutral instrumentation standard; most backends (Datadog, Honeycomb, Jaeger, Tempo) accept its output. Sampling strategies, span-attribute conventions, and exemplar correlation between metrics and traces are intentionally out of scope here — they depend on the vendor.

**Correlation IDs — the thread through all three.** A correlation ID is a single identifier that ties together every log line, span, metric exemplar, and downstream call belonging to one logical operation. Without it, the three pillars become three disconnected views of the same incident.

A common implementation:

- **Generated at the edge.** The controller mints a new correlation ID for each incoming request, or honors `X-Request-Id` (or `traceparent`) if it arrives from a trusted upstream.
- **Carried through the call stack.** A request-scoped context (logger, tracer, or explicit parameter) carries the ID into use cases, repositories, and external clients so every emitted log line and span tags it automatically.
- **Propagated to async work.** When a use case enqueues a job, the job payload carries the originating correlation ID. The handler logs and traces under the same ID so the async leg is correlatable with the request that triggered it.
- **Propagated to external calls.** Outbound HTTP requests forward the ID via header (`X-Request-Id`, `traceparent`) so the third party's logs can be tied back to yours during incident response.

Two pitfalls worth flagging: regenerating the correlation ID mid-flow (breaks the chain), and conflating it with user ID or session ID (correlation IDs are per-operation; identity is per-user — they are orthogonal).

**Pluggable interfaces.** Like every other I/O concern in this blueprint, observability sits behind interfaces — `LoggerInterface`, `MetricsInterface`, `TracerInterface`. Implementations swap (file, stdout, vendor SDK, OTLP exporter) without touching use cases. Use cases and domain services depend on the interfaces and emit signals through them; they never know which backend is on the other end.

### CI/CD Pipeline

> *The pipeline gates below are load-bearing — every PR passes them or it does not merge. The specific tooling (test runner, static analyzer, mutation framework, deploy targets, CI provider) is organization-specific; this section names the gates, not the tools.*

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

> *This is the essential baseline — controls that apply to most web applications. Production systems require a threat model for their specific domain, attack surface, and regulatory context; the list below covers the floor, not the ceiling.*

- **Parameterized queries only.** No string concatenation in SQL — ever. Repositories use prepared statements.
- **Escape all output.** Context-appropriate escaping for HTML, JSON, URLs. No raw user input in templates.
- **HTTPS only.** Redirect HTTP → HTTPS. Set `Strict-Transport-Security` header.
- **CORS configured explicitly.** Whitelist allowed origins — never `*` in production.
- **No secrets in code or logs.** Environment variables for credentials. Secret files never committed.
- **Dependency audits.** Run audit tools in CI — fail on known vulnerabilities.
- **Content Security Policy.** Restrict inline scripts, external resources. Prevents XSS escalation.

## Subscriptions & Payments

> *This section is a worked example from one product domain, not a universal blueprint. The reusable principles are the separation of concerns (external provider owns billing, local store owns feature gating) and the provider-agnostic interface boundary. The schema and flow specifics are illustrative — your subscription model, top-up types, and webhook contracts will differ.*

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

### Contract Maturity

The requirements below are the **target state** — not the day-one state. During early exploration of a new system or aggregate, interfaces change fast. Tests written against an unstable interface are waste — they get rewritten (or deleted) as the contract shifts. The blueprint's own principle *"no premature abstraction — add structure when pain arrives, not before"* applies to test structure as much as to code structure.

Since every injected dependency is already an interface (see [Design Principles](#design-principles) and the DI rules throughout this document), the interface **is** the contract. The interface stabilising is the maturity signal that gates when the 100%-coverage bar starts to bite.

**Two states per interface.**

- **`exploration`** *(default)*. No test bar. Implementations may merge without tests. This is a deliberate reprieve, not an oversight — the contract is still being discovered.
- **`sealed`**. Blueprint-grade tests are required for any **new or modified** implementation of this interface. Sealing is a **deliberate human act** — a marker on the interface itself, chosen by whoever's working on the code (mechanism is language-specific: a documentation annotation, a per-aggregate note, whatever the project settles on). Unsealing before a planned refactor is also allowed — it's a decision, not a slip.

**Mechanics of a sealed interface.**

- **New implementations** — merge only with tests meeting the requirements below.
- **Modifications to existing implementations** — same bar.
- **Pre-seal implementations** — file a follow-up to backfill tests. Triaged separately; does not block current work.
- **The interface itself cannot change** without being unsealed first. If a refactor is coming, unseal deliberately, then reseal when the shape settles again.

**What signals that an interface is ready to seal.**

- The interface hasn't been modified for a while (measured in whatever unit fits the project — pull requests since last change, days, release cycles).
- Downstream consumers have stopped requesting shape changes.
- A human — not a threshold — makes the call. Any automatic gate on a raw metric (PR count, calendar days) invites gaming and false signals. Use the metric as a *nudge* during review ("this interface hasn't changed in a while — seal or defer?"), never as an auto-seal.

**Day-1 carve-outs — always tested, no seal ceremony required.**

Some categories are their own contract by nature and cost nothing to test even during churn:

- **Value objects.** Invariants and equality are stable by definition.
- **Pure parsers.** Input → output is the contract; the test is the specification.
- **DTOs.** Trivial to test; break loudly when the shape changes, which is often useful.

Skipping these has no upside — they'd get tests eventually, and writing them early doesn't paint the project into a corner.

**Why this doesn't devolve into "no tests ever."**

- The default is not "skip tests." The default is *"this interface is in `exploration` — the contract is provisional, so tests are deferred."* The framing is temporal, not permanent.
- Sealing is an ordinary part of moving code toward production. Any implementation crossing into a real request path, a release milestone, or another team's dependency triggers the seal question — and once sealed, the coverage bar is unconditional.
- The nudge mechanism (interface unchanged for a while → review asks "seal or defer?") ensures the question actually gets asked, without turning into a hard gate that people work around.

### Requirements

- **100% code coverage.** Every class, every method, every branch. *(Applies once the interface is sealed — see [Contract Maturity](#contract-maturity).)*
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
- **Domain tests** need no doubles. Entities and value objects are immutable data; domain services are pure functions over them. Construct, call, assert.
- **Repository tests** are both: unit tests that stub the data source to cover branching/orchestration logic, *and* integration tests against a real test database that verify the SQL works against the actual schema. Neither replaces the other.

**On repository (and other infrastructure adapter) unit tests.** Repositories, cache adapters, queue adapters, and external API clients contain orchestration logic — null handling, conditional mapping, optional joins, fallback paths — that the 100% coverage requirement makes mandatory to test at the unit level. Integration tests alone cannot economically cover every branch, and the existence of a mapper does not eliminate the orchestration around it. Unit tests on these classes are required, not optional. The rule is what they assert:

- **Valid** — behavior visible from the return value: "null in → null out", "row with field X → entity with field X", "two rows → list of two entities", "missing optional field → entity with default".
- **Invalid** — implementation visible only through mocks: the exact SQL string, parameter binding order, driver method signatures, internal call sequences.

The integration test owns the SQL/schema contract. The unit test owns the logic that lives in the class. Both exist; neither replaces the other.

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

**What to keep regardless of scale:**

- Use case interfaces — contracts, not overhead. Enable test doubles, explicit DI, and readable boundaries.
- One use case = one transaction boundary
- Repositories and external services behind interfaces

## Anti-Patterns

What this blueprint explicitly does not do. Each rule is enforced somewhere in the standards above — this section consolidates them for fast review and onboarding.

### Architecture

- **Use cases calling other use cases.** Creates implicit dependency graphs, hidden transactions, and untestable nesting. Extract shared logic into a domain service or repository method; orchestrate from one use case only.
- **Business logic in controllers.** Controllers parse, validate, delegate, format. Branching on domain state, computing totals, deciding what to persist — all belong in the use case.
- **Mutating methods on an entity.** Entities are immutable (see [Design Principles](#design-principles)). State transitions are domain services that take the entity and return a new instance — never `booking.confirm()` mutating in place.
- **God-services / multi-operation domain services.** `BookingManager` with `confirm()`, `cancel()`, `reschedule()` is the symmetric anti-pattern to god-entities. Split into one service per operation (`BookingConfirmer`, `BookingCanceller`, `BookingRescheduler`).
- **Repository-to-repository calls.** Cross-aggregate orchestration happens in the use case or domain service, not by chaining repositories.
- **Domain objects performing I/O.** No HTTP calls, queue dispatches, or filesystem writes from entities, value objects, or domain services. Side effects live behind interfaces, invoked from use cases.
- **Shared Service holding domain concepts.** If a class in `Shared/` references `Booking` or any domain type, it is a **Domain Service**, not shared. `Shared/` is for technical utilities with no domain knowledge.
- **Framework types in the domain.** Use cases and entities import nothing framework-specific. HTTP requests stop at the controller. ORM models do not exist — repositories return domain objects, not framework rows.

### Dependencies

- **Service locator / global container access.** A class that pulls from a global registry hides its contract. Dependencies are constructor-injected, always.
- **Static facades and ambient singletons.** Same problem in different clothing: invisible dependencies, untestable code, magical resolution.
- **Concrete dependencies bypassing interfaces.** Use cases depend on `*Interface`, not on the SQL or HTTP implementation directly.

### Data

- **ORMs.** Repositories write parameterized SQL directly. ORMs hide cost (N+1, lazy loading) and bleed persistence concerns into the domain.
- **String concatenation in SQL.** Parameterized queries only — including for "internal" or "trusted" input. There is no trusted input.
- **Editing a deployed migration.** Schema history is append-only. Generate a new migration; never rewrite history that has run anywhere.
- **`DOWN` migrations against live data.** Rollback is *code* rollback to the previous SHA. Live schema reversals corrupt data and the expand-contract pattern.

### State and caching

- **Cache as a source of truth.** Caches are rebuildable performance optimizations. If losing the cache loses data, it is not a cache.
- **Time-based TTLs as the primary invalidation strategy.** Invalidate explicitly on write. TTL is a fallback for slow-changing data, not the default.
- **Process-local caches without a coherence model.** Long-lived processes that cache domain data must tolerate staleness within a known TTL or invalidate via a shared mechanism. See [State in Long-Lived Processes](#state-in-long-lived-processes).

### Testing

- **Mocks where stubs suffice.** Mocks signal "the side effect *is* the behavior being verified." Stubs signal "this is a placeholder." Default to stubs.
- **Asserting SQL strings or driver call signatures in repository unit tests.** Couples tests to implementation — the SQL can change without behavior changing, and the test breaks. Stub the data source to verify branching logic (null handling, mapping, orchestration), but never assert *how* the query was constructed. The schema contract is verified by integration tests. See [Isolation Per Layer](#isolation-per-layer).
- **Tests coupled to implementation.** Test names describe behavior, not method calls. Renaming a private method must not break a test.

### Process

- **Premature scaling patterns.** Canary, blue-green, feature-first folders, DTOs — adopt when rolling deploys or 50+ use cases cause real pain. Not before.
- **Bypassing CI for "just this once."** Hotfixes use the reduced pipeline (see [Hotfix Process](#hotfix-process)), never an empty one. `--no-verify` is not a hotfix tool.
- **Tokens leaking past the auth boundary.** Controllers extract `userId` from the token. Use cases receive identifiers, never raw tokens or session objects.

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
- **Machine-checkable rules where possible.** Architectural constraints expressed in linters, static analysis, dependency-direction checks, and test fixtures catch drift automatically — regardless of whether the author is human or AI. Rules that live only in prose drift faster the more code AI generates. Codify what can be codified; documentation is the fallback, not the primary enforcement layer.
- **Quality gates are non-negotiable.** Tests, static analysis, mutation testing, and CI thresholds apply equally to human and AI contributions.
- **Existing patterns first.** AI-generated code references the patterns already in the codebase before introducing new ones. A novel shape of repository, controller, or service inflates the architectural surface area without earning it. If no existing pattern fits, the introduction is reviewed as an architectural change — not waved through as an implementation detail.
- **Code must remain understandable.** Developers stay accountable — AI output is reviewed and maintained like any other code.
- **AI reinforces discipline.** Faster feedback loops, better tests, clearer code. Backed by architecture and policies that prevent surprises.

> *This section is the blueprint's most open to revision. AI tooling, integration patterns, prompting conventions, and team workflows around AI-assisted work are all evolving quickly; rules here are expected to be added, sharpened, or retired as practice catches up with the technology. Treat the current bullets as a starting position, not a settled stance.*
