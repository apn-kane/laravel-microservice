# Laravel Microservices — Event-Driven Architecture

> Simple, standardized, Dockerized backend API microservices with Laravel 12.

---

## Architecture Diagram

```
┌─────────────┐
│   Client    │
└──────┬──────┘
       │ HTTP
┌──────▼──────┐
│ API Gateway │  (port 8080)
└──┬───┬───┬──┘
   │   │   │  HTTP (internal)
┌──▼┐ ┌▼──┐ ┌▼────────┐
│User│ │Order│ │Product  │
│Svc │ │Svc  │ │Service  │
└──┬─┘ └─┬──┘ └──┬──────┘
   │      │       │
   │      └──► RabbitMQ ◄──┐
   │          └────┬────┘   │
   │               │ order.created
   │          ┌────▼────┐   │
   │          │ Product │───┘
   │          │(consumer)│
   │          └─────────┘
┌──▼─┐  ┌───▼──┐  ┌──────▼──┐
│user │  │order │  │product  │
│_db  │  │_db   │  │_db      │
└─────┘  └──────┘  └─────────┘
       PostgreSQL (per service)
```

---

## Services

| Service | Port | Responsibility | Database |
|---|---|---|---|
| **API Gateway** | 8080 | Routes requests, rate limiting | None (stateless) |
| **User Service** | 8001 | User CRUD, authentication | `users_db` |
| **Order Service** | 8002 | Order management, publishes events | `orders_db` |
| **Product Service** | 8003 | Product catalog, consumes events (stock) | `products_db` |

---

## Event Flow

```
Order Created → Order Service publishes "order.created" to RabbitMQ
             → Product Service consumes event → decrements stock
             → (Future: Notification Service → sends email)
```

---

## Tech Stack

| Technology | Version | Purpose |
|---|---|---|
| Laravel | 12 | Application framework |
| PHP | 8.3 | Runtime |
| PostgreSQL | 16 | Database (one per service) |
| RabbitMQ | 3-management | Message broker (event-driven) |
| Docker | Latest | Containerization |
| Nginx | Alpine | Reverse proxy / web server |

---

## File Structure

```
laravel-microservice/
├── docker-compose.yml                  # All services, DBs, RabbitMQ
├── .env.example                        # Root env template
├── README.md
│
├── overview/
│   └── OVERVIEW.md                     # This file
│
├── docker/
│   ├── php/
│   │   └── Dockerfile                  # Shared Dockerfile for all Laravel services
│   └── nginx/
│       ├── api-gateway.conf
│       ├── user-service.conf
│       ├── order-service.conf
│       └── product-service.conf
│
├── api-gateway/                        # Laravel app — request routing
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   │   └── GatewayController.php
│   │   │   └── Middleware/
│   │   │       └── RateLimitMiddleware.php
│   │   └── Services/
│   │       └── HttpClient.php          # Resilient HTTP client with retry logic
│   ├── config/
│   │   └── services.php                # Service URL registry
│   ├── routes/
│   │   └── api.php
│   ├── .env.example
│   └── ... (standard Laravel scaffolding)
│
├── user-service/                       # Laravel app — user management
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   └── UserController.php
│   │   ├── Models/
│   │   │   └── User.php
│   │   ├── Events/
│   │   │   └── UserCreated.php
│   │   └── Jobs/
│   │       └── PublishEvent.php
│   ├── database/migrations/
│   │   └── xxxx_create_users_table.php
│   ├── routes/api.php
│   ├── .env.example
│   └── ...
│
├── order-service/                      # Laravel app — order management
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   └── OrderController.php
│   │   ├── Models/
│   │   │   ├── Order.php
│   │   │   └── OrderItem.php
│   │   ├── Events/
│   │   │   └── OrderCreated.php
│   │   ├── Listeners/
│   │   │   └── PublishOrderCreatedEvent.php
│   │   ├── Jobs/
│   │   │   └── PublishEvent.php
│   │   └── Services/
│   │       ├── UserServiceClient.php   # HTTP client to user-service
│   │       └── ProductServiceClient.php
│   ├── database/migrations/
│   │   ├── xxxx_create_orders_table.php
│   │   └── xxxx_create_order_items_table.php
│   ├── routes/api.php
│   ├── .env.example
│   └── ...
│
├── product-service/                    # Laravel app — product catalog
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   └── ProductController.php
│   │   ├── Models/
│   │   │   └── Product.php
│   │   ├── Console/Commands/
│   │   │   └── ConsumeEvents.php       # RabbitMQ consumer command
│   │   └── Handlers/
│   │       └── OrderCreatedHandler.php  # Decrements stock on order.created
│   ├── database/migrations/
│   │   └── xxxx_create_products_table.php
│   ├── routes/api.php
│   ├── .env.example
│   └── ...
│
└── shared/
    └── helpers.php                     # Minimal shared utilities (optional)
```

---

## API Endpoints

### User Service (`/api/v1/users`)

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/users` | List users (paginated) |
| GET | `/api/v1/users/{id}` | Get user by ID |
| POST | `/api/v1/users` | Create user |
| PUT | `/api/v1/users/{id}` | Update user |
| DELETE | `/api/v1/users/{id}` | Delete user |

### Order Service (`/api/v1/orders`)

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/orders` | List orders (paginated) |
| GET | `/api/v1/orders/{id}` | Get order by ID |
| POST | `/api/v1/orders` | Create order → publishes `order.created` |
| PUT | `/api/v1/orders/{id}` | Update order |
| DELETE | `/api/v1/orders/{id}` | Cancel order |

### Product Service (`/api/v1/products`)

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/products` | List products (paginated) |
| GET | `/api/v1/products/{id}` | Get product by ID |
| POST | `/api/v1/products` | Create product |
| PUT | `/api/v1/products/{id}` | Update product |
| DELETE | `/api/v1/products/{id}` | Delete product |

### API Gateway (`localhost:8080`)

| Method | Endpoint | Proxied To |
|---|---|---|
| ANY | `/api/{service}/{path}` | Routes to corresponding service |
| GET | `/health` | Gateway health check |

### All Services

| Method | Endpoint | Description |
|---|---|---|
| GET | `/health` | Service health check with DB/cache status |

---

## Docker Compose Services

| Container | Image | Exposed Port |
|---|---|---|
| `api-gateway` | PHP 8.3 + Nginx | 8080 |
| `user-service` | PHP 8.3 + Nginx | 8001 |
| `order-service` | PHP 8.3 + Nginx | 8002 |
| `product-service` | PHP 8.3 + Nginx | 8003 |
| `user-db` | PostgreSQL 16 | 5432 |
| `order-db` | PostgreSQL 16 | 5433 |
| `product-db` | PostgreSQL 16 | 5434 |
| `rabbitmq` | RabbitMQ 3-management | 5672 / 15672 |

---

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Framework | Laravel 12 | Lumen archived since 2024; Laravel + Octane is modern |
| Database | PostgreSQL 16 | Better JSON support, robust for microservices |
| Message Broker | RabbitMQ | Production-grade topic exchanges for event routing |
| Dockerfile | Shared (one for all) | DRY — identical PHP runtime needs |
| Notification Service | Excluded | Minimal scope; easy to add later |
| DB per service | Separate containers | True data isolation |
| API versioning | `/api/v1/` prefix | Independent service evolution |
