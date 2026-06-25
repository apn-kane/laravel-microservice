# Implementation Plan — Step-by-Step TODO

> Build a Laravel Microservices project with Kafka for async messaging.

### Detailed Guides

| Phase | Guide |
|-------|-------|
| 1 | [Infrastructure & Docker Setup](./phase-1-infrastructure.md) |
| 2 | [User Service](./phase-2-user-service.md) |
| 3 | [Product Service](./phase-3-product-service.md) |
| 4 | [Order Service](./phase-4-order-service.md) |
| 5 | [API Gateway](./phase-5-api-gateway.md) |
| 6 | [Integration & Testing](./phase-6-integration-testing.md) |

---

## Phase 1: Infrastructure & Docker Setup

- [ ] 1.1 — Create `docker/php/Dockerfile` (PHP 8.3-FPM, Composer, `rdkafka` extension, required PHP extensions)
- [ ] 1.2 — Create Nginx config files: `docker/nginx/api-gateway.conf`, `user-service.conf`, `product-service.conf`, `order-service.conf`
- [ ] 1.3 — Create `docker-compose.yml` with: 4 PHP-FPM containers, 4 Nginx containers, 3× PostgreSQL (one per service), Kafka (+ Zookeeper), Kafka UI (optional)
- [ ] 1.4 — Create root `.env.example` with all service ports, DB credentials, Kafka broker address
- [ ] 1.5 — Run `docker compose up --build` and verify all containers start without errors

---

## Phase 2: User Service

- [ ] 2.1 — Scaffold Laravel 12 project in `user-service/` (`composer create-project laravel/laravel user-service`)
- [ ] 2.2 — Configure PostgreSQL: update `.env` (`DB_CONNECTION=pgsql`, `DB_HOST=user-db`, etc.) and verify `config/database.php`
- [ ] 2.3 — Create `User` model + migration (name, email, password, timestamps)
- [ ] 2.4 — Create `UserController` with methods: `index`, `show`, `store`, `update`, `destroy`
- [ ] 2.5 — Define API routes in `routes/api.php` → `Route::apiResource('v1/users', UserController::class)`
- [ ] 2.6 — Add `GET /health` endpoint returning `{ "status": "ok", "service": "user-service" }`
- [ ] 2.7 — Install `mateusjunges/laravel-kafka` package (`composer require mateusjunges/laravel-kafka`)
- [ ] 2.8 — Create `UserCreated` event class
- [ ] 2.9 — Create a Kafka producer job/listener that publishes `user.created` to Kafka topic when a user is created
- [ ] 2.10 — Publish Kafka config: `php artisan vendor:publish --tag=laravel-kafka-config`
- [ ] 2.11 — Run migrations (`php artisan migrate`) and test all CRUD endpoints with curl/Postman

---

## Phase 3: Product Service

- [ ] 3.1 — Scaffold Laravel 12 project in `product-service/`
- [ ] 3.2 — Configure PostgreSQL: `.env` (`DB_HOST=product-db`), verify `config/database.php`
- [ ] 3.3 — Create `Product` model + migration (name, description, price [decimal], stock [integer], timestamps)
- [ ] 3.4 — Create `ProductController` with CRUD methods
- [ ] 3.5 — Define routes: `Route::apiResource('v1/products', ProductController::class)`
- [ ] 3.6 — Add `GET /health` endpoint
- [ ] 3.7 — Install `mateusjunges/laravel-kafka`
- [ ] 3.8 — Create Kafka consumer artisan command (`php artisan kafka:consume`) that listens to `order.created` topic
- [ ] 3.9 — Create `OrderCreatedHandler` — when receiving `order.created` event, decrement product stock
- [ ] 3.10 — Run migrations and test CRUD endpoints

---

## Phase 4: Order Service

- [ ] 4.1 — Scaffold Laravel 12 project in `order-service/`
- [ ] 4.2 — Configure PostgreSQL: `.env` (`DB_HOST=order-db`)
- [ ] 4.3 — Create `Order` model + migration (user_id, status, total, timestamps)
- [ ] 4.4 — Create `OrderItem` model + migration (order_id, product_id, quantity, price)
- [ ] 4.5 — Define Eloquent relationships: `Order hasMany OrderItem`, `OrderItem belongsTo Order`
- [ ] 4.6 — Create `OrderController` with CRUD methods
- [ ] 4.7 — Define routes: `Route::apiResource('v1/orders', OrderController::class)`
- [ ] 4.8 — Add `GET /health` endpoint
- [ ] 4.9 — Create `UserServiceClient` — HTTP client to call user-service internally (validate user exists)
- [ ] 4.10 — Create `ProductServiceClient` — HTTP client to call product-service (validate products & check stock)
- [ ] 4.11 — Install `mateusjunges/laravel-kafka`
- [ ] 4.12 — Create Kafka producer that publishes `order.created` event (with order items/product IDs) after order is stored
- [ ] 4.13 — Run migrations and test: create an order → verify Kafka message is published

---

## Phase 5: API Gateway

- [ ] 5.1 — Scaffold Laravel 12 project in `api-gateway/` (already exists — configure it)
- [ ] 5.2 — Create `App\Services\HttpClient` — a resilient HTTP wrapper with retry logic (3 retries, exponential backoff)
- [ ] 5.3 — Create `GatewayController` that proxies requests to backend services based on URL prefix
- [ ] 5.4 — Create `RateLimitMiddleware` (e.g., 60 requests/minute per IP)
- [ ] 5.5 — Define gateway routes in `routes/api.php`:
  - `api/v1/users/*` → user-service
  - `api/v1/products/*` → product-service
  - `api/v1/orders/*` → order-service
- [ ] 5.6 — Configure internal service URLs in `config/services.php` (use Docker service names as hosts)
- [ ] 5.7 — Add `GET /health` endpoint (also checks downstream service health)
- [ ] 5.8 — Test end-to-end: Client → Gateway → Backend Service → Database

---

## Phase 6: Integration & Testing

- [ ] 6.1 — Test full event flow: Create Order → Kafka `order.created` → Product service consumes → stock decremented
- [ ] 6.2 — Test gateway proxying to all 3 backend services
- [ ] 6.3 — Test `/health` endpoints on all 4 services
- [ ] 6.4 — Run `docker compose down -v && docker compose up --build` — verify clean start
- [ ] 6.5 — (Optional) Add Kafka UI and verify topics/messages at `http://localhost:8080`

---

## Execution Order

```
Phase 1 (Docker + Kafka)
    │
    ├── Phase 2 (User Service)
    ├── Phase 3 (Product Service)    ← can be parallel with Phase 2
    │
    └── Phase 4 (Order Service)      ← depends on User & Product existing
            │
            └── Phase 5 (API Gateway)
                    │
                    └── Phase 6 (Integration)
```

---

## Key Details

- Each service is a **separate Laravel 12** installation
- Services communicate internally via Docker network (`microservices`)
- **Kafka** broker: `kafka:9092` (internal), `localhost:9093` (external/host)
- **Kafka UI** (optional): `http://localhost:8080`
- **Zookeeper**: required by Kafka, runs on port `2181`
- Each PostgreSQL instance is isolated per service (user-db:5432, product-db:5432, order-db:5432)
- Kafka PHP package: [`mateusjunges/laravel-kafka`](https://github.com/mateusjunges/laravel-kafka)
- PHP extension needed: `rdkafka` (install via `pecl install rdkafka` in Dockerfile)
