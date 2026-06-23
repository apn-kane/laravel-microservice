# Implementation Plan

> Step-by-step plan for building the Laravel Microservices project.

---

## Phase 1: Infrastructure & Docker Setup

| # | Task | Status |
|---|---|---|
| 1.1 | Create shared `docker/php/Dockerfile` (PHP 8.3-FPM, Composer, extensions) | ⬜ |
| 1.2 | Create Nginx config files for each service | ⬜ |
| 1.3 | Create `docker-compose.yml` (all services, 3x PostgreSQL, RabbitMQ) | ⬜ |
| 1.4 | Create root `.env.example` | ⬜ |
| 1.5 | Verify `docker compose up` boots all containers | ⬜ |

---

## Phase 2: User Service

| # | Task | Status |
|---|---|---|
| 2.1 | Scaffold Laravel project in `user-service/` | ⬜ |
| 2.2 | Configure PostgreSQL connection (`.env`, `config/database.php`) | ⬜ |
| 2.3 | Create `User` model + migration | ⬜ |
| 2.4 | Create `UserController` (CRUD: index, show, store, update, destroy) | ⬜ |
| 2.5 | Define routes in `routes/api.php` under `/api/v1/users` | ⬜ |
| 2.6 | Add health check endpoint (`/health`) | ⬜ |
| 2.7 | Create `UserCreated` event + `PublishEvent` job | ⬜ |
| 2.8 | Run migration & test endpoints | ⬜ |

---

## Phase 3: Product Service

| # | Task | Status |
|---|---|---|
| 3.1 | Scaffold Laravel project in `product-service/` | ⬜ |
| 3.2 | Configure PostgreSQL connection | ⬜ |
| 3.3 | Create `Product` model + migration (name, description, price, stock) | ⬜ |
| 3.4 | Create `ProductController` (CRUD) | ⬜ |
| 3.5 | Define routes under `/api/v1/products` | ⬜ |
| 3.6 | Add health check endpoint | ⬜ |
| 3.7 | Create `ConsumeEvents` artisan command (RabbitMQ consumer) | ⬜ |
| 3.8 | Create `OrderCreatedHandler` (decrement stock on `order.created`) | ⬜ |
| 3.9 | Run migration & test endpoints | ⬜ |

---

## Phase 4: Order Service

| # | Task | Status |
|---|---|---|
| 4.1 | Scaffold Laravel project in `order-service/` | ⬜ |
| 4.2 | Configure PostgreSQL connection | ⬜ |
| 4.3 | Create `Order` + `OrderItem` models + migrations | ⬜ |
| 4.4 | Create `OrderController` (CRUD) | ⬜ |
| 4.5 | Define routes under `/api/v1/orders` | ⬜ |
| 4.6 | Add health check endpoint | ⬜ |
| 4.7 | Create `UserServiceClient` + `ProductServiceClient` (HTTP clients) | ⬜ |
| 4.8 | Create `OrderCreated` event + `PublishOrderCreatedEvent` listener | ⬜ |
| 4.9 | Create `PublishEvent` job (publishes to RabbitMQ) | ⬜ |
| 4.10 | Run migration & test endpoints + event publishing | ⬜ |

---

## Phase 5: API Gateway

| # | Task | Status |
|---|---|---|
| 5.1 | Scaffold Laravel project in `api-gateway/` | ⬜ |
| 5.2 | Create `HttpClient` service (resilient, with retry logic) | ⬜ |
| 5.3 | Create `GatewayController` (proxy requests to backend services) | ⬜ |
| 5.4 | Create `RateLimitMiddleware` | ⬜ |
| 5.5 | Define gateway routes in `routes/api.php` | ⬜ |
| 5.6 | Configure service URLs in `config/services.php` | ⬜ |
| 5.7 | Add health check endpoint | ⬜ |
| 5.8 | Test end-to-end: Client → Gateway → Service → DB | ⬜ |

---

## Phase 6: Integration & Testing

| # | Task | Status |
|---|---|---|
| 6.1 | Test event flow: Create Order → RabbitMQ → Product stock decremented | ⬜ |
| 6.2 | Test gateway proxying to all 3 services | ⬜ |
| 6.3 | Test health endpoints on all services | ⬜ |
| 6.4 | Verify all containers start clean with `docker compose up --build` | ⬜ |

---

## Execution Order

```
Phase 1 (Docker)
    │
    ├── Phase 2 (User Service)
    ├── Phase 3 (Product Service)    ← can be parallel
    │
    └── Phase 4 (Order Service)      ← depends on User & Product services existing
            │
            └── Phase 5 (API Gateway)
                    │
                    └── Phase 6 (Integration)
```

---

## Notes

- Each service is a **separate Laravel 12** installation
- Services communicate internally via Docker network (`microservices`)
- RabbitMQ Management UI available at `http://localhost:15672` (guest/guest)
- Each PostgreSQL instance is isolated per service
