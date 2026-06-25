# Phase 6: Integration & Testing

> Verify the entire system works end-to-end: all services, Kafka messaging, and the API Gateway.

---

## Prerequisites

- All phases 1-5 complete
- All Docker containers running (`docker compose up -d`)
- Product Service Kafka consumer running in a terminal

---

## Step 6.1 — Test Full Event Flow (Order → Kafka → Stock Decrement)

This is the most critical integration test. It verifies:
1. Order Service validates user/product via HTTP
2. Order is created in the database
3. Kafka `order.created` event is published
4. Product Service consumer receives the event
5. Product stock is decremented

### Setup:

```bash
# Terminal 1: Start the Product Service Kafka consumer
docker compose exec product-service php artisan kafka:consume-orders
```

### Execute:

```bash
# 1. Create a user
curl -s -X POST http://localhost:8000/api/v1/users \
  -H "Content-Type: application/json" \
  -d '{"name": "Test User", "email": "test@example.com", "password": "password123"}' | jq .

# Note the user ID (should be 1)

# 2. Create a product with known stock
curl -s -X POST http://localhost:8000/api/v1/products \
  -H "Content-Type: application/json" \
  -d '{"name": "Integration Test Product", "description": "For testing", "price": 25.00, "stock": 50}' | jq .

# Note the product ID (should be 1)

# 3. Check current stock
curl -s http://localhost:8000/api/v1/products/1 | jq '.data.stock'
# Expected: 50

# 4. Create an order (this triggers the entire flow)
curl -s -X POST http://localhost:8000/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{"user_id": 1, "items": [{"product_id": 1, "quantity": 5}]}' | jq .

# 5. Wait 2-3 seconds for Kafka event to be processed, then check stock
curl -s http://localhost:8000/api/v1/products/1 | jq '.data.stock'
# Expected: 45 (50 - 5)

# 6. Create another order with multiple items
curl -s -X POST http://localhost:8000/api/v1/products \
  -H "Content-Type: application/json" \
  -d '{"name": "Second Product", "description": "Another one", "price": 15.00, "stock": 30}' | jq .

curl -s -X POST http://localhost:8000/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{"user_id": 1, "items": [{"product_id": 1, "quantity": 3}, {"product_id": 2, "quantity": 2}]}' | jq .

# 7. Verify both products had stock decremented
curl -s http://localhost:8000/api/v1/products/1 | jq '.data.stock'
# Expected: 42 (45 - 3)

curl -s http://localhost:8000/api/v1/products/2 | jq '.data.stock'
# Expected: 28 (30 - 2)
```

### Verify in Kafka UI:

1. Open `http://localhost:8080`
2. Go to Topics → `order-events`
3. View Messages tab — you should see 2 messages with full order data

---

## Step 6.2 — Test Gateway Proxying to All Services

### User Service via Gateway:

```bash
# List users
curl -s http://localhost:8000/api/v1/users | jq .

# Get specific user
curl -s http://localhost:8000/api/v1/users/1 | jq .

# Update user
curl -s -X PUT http://localhost:8000/api/v1/users/1 \
  -H "Content-Type: application/json" \
  -d '{"name": "Updated Name"}' | jq .

# Delete user (creates new one first)
curl -s -X POST http://localhost:8000/api/v1/users \
  -H "Content-Type: application/json" \
  -d '{"name": "Delete Me", "email": "delete@test.com", "password": "password123"}' | jq .

curl -s -X DELETE http://localhost:8000/api/v1/users/2 | jq .
```

### Product Service via Gateway:

```bash
# List products
curl -s http://localhost:8000/api/v1/products | jq .

# Get specific product
curl -s http://localhost:8000/api/v1/products/1 | jq .

# Update product
curl -s -X PUT http://localhost:8000/api/v1/products/1 \
  -H "Content-Type: application/json" \
  -d '{"price": 19.99}' | jq .
```

### Order Service via Gateway:

```bash
# List orders (with items)
curl -s http://localhost:8000/api/v1/orders | jq .

# Get specific order
curl -s http://localhost:8000/api/v1/orders/1 | jq .

# Update order status
curl -s -X PUT http://localhost:8000/api/v1/orders/1 \
  -H "Content-Type: application/json" \
  -d '{"status": "cancelled"}' | jq .
```

### Test error cases:

```bash
# Order with non-existent user
curl -s -X POST http://localhost:8000/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{"user_id": 9999, "items": [{"product_id": 1, "quantity": 1}]}' | jq .
# Expected: 422 "User not found"

# Order with non-existent product
curl -s -X POST http://localhost:8000/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{"user_id": 1, "items": [{"product_id": 9999, "quantity": 1}]}' | jq .
# Expected: 422 "Product 9999 not found"

# Order with insufficient stock
curl -s -X POST http://localhost:8000/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{"user_id": 1, "items": [{"product_id": 1, "quantity": 99999}]}' | jq .
# Expected: 422 "Insufficient stock"
```

---

## Step 6.3 — Test Health Endpoints

```bash
# Gateway health (includes downstream checks)
curl -s http://localhost:8000/api/health | jq .
# Expected: status "ok", all downstream services "healthy"

# Direct service health checks
curl -s http://localhost:8001/api/health | jq .
curl -s http://localhost:8002/api/health | jq .
curl -s http://localhost:8003/api/health | jq .
```

### Test degraded state:

```bash
# Stop user service
docker compose stop user-service user-nginx

# Check gateway health again
curl -s http://localhost:8000/api/health | jq .
# Expected: status "degraded", user-service "unreachable"

# Restart user service
docker compose start user-service user-nginx
```

---

## Step 6.4 — Clean Start Verification

This tests that everything works from a completely fresh state.

```bash
# Stop everything and remove volumes
docker compose down -v

# Rebuild and start
docker compose up --build -d

# Wait for containers to be healthy (30-60 seconds)
sleep 30

# Check all containers are running
docker compose ps

# Run migrations on each service
docker compose exec user-service php artisan migrate --force
docker compose exec product-service php artisan migrate --force
docker compose exec order-service php artisan migrate --force

# Start Kafka consumer
docker compose exec -d product-service php artisan kafka:consume-orders

# Verify health
curl -s http://localhost:8000/api/health | jq .

# Run a quick integration test
curl -s -X POST http://localhost:8000/api/v1/users \
  -H "Content-Type: application/json" \
  -d '{"name": "Fresh Start", "email": "fresh@test.com", "password": "password123"}' | jq .

curl -s -X POST http://localhost:8000/api/v1/products \
  -H "Content-Type: application/json" \
  -d '{"name": "Fresh Product", "price": 10.00, "stock": 100}' | jq .

curl -s -X POST http://localhost:8000/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{"user_id": 1, "items": [{"product_id": 1, "quantity": 1}]}' | jq .

# Verify stock decremented
curl -s http://localhost:8000/api/v1/products/1 | jq '.data.stock'
# Expected: 99
```

---

## Step 6.5 — Kafka UI Verification (Optional)

1. Open `http://localhost:8080` in your browser
2. You should see the cluster "local" listed

### Check topics:

- `user-events` — messages from User Service
- `order-events` — messages from Order Service

### For each topic:

1. Click the topic name
2. Go to "Messages" tab
3. Verify messages contain the correct JSON structure
4. Check partitions and consumer groups

### Expected consumer groups:

- `product-service-group` — consuming from `order-events`

---

## ✅ Phase 6 Complete Checklist

- [ ] Full event flow works: Order → Kafka → Stock decrement
- [ ] All CRUD operations work through the gateway for all 3 services
- [ ] Error cases return proper HTTP status codes (422, 503)
- [ ] Health endpoints return correct status for all services
- [ ] Gateway shows "degraded" when a service is down
- [ ] Clean `docker compose down -v && up --build` works without issues
- [ ] All migrations run cleanly on fresh databases
- [ ] Kafka UI shows topics and messages correctly
- [ ] Rate limiting works (429 after 60 requests/minute)

---

## Troubleshooting Guide

### Container won't start:

```bash
docker compose logs <service-name>
# e.g., docker compose logs user-service
```

### Kafka consumer not receiving messages:

```bash
# Check if topic exists
docker compose exec kafka kafka-topics --list --bootstrap-server localhost:9092

# Check consumer group lag
docker compose exec kafka kafka-consumer-groups --bootstrap-server localhost:9092 --describe --group product-service-group

# Check if message was produced
docker compose exec kafka kafka-console-consumer --bootstrap-server localhost:9092 --topic order-events --from-beginning
```

### Database connection refused:

```bash
# Check if DB container is running
docker compose ps | grep db

# Test connection from service container
docker compose exec order-service php artisan tinker
# >>> DB::connection()->getPdo();
```

### Gateway returns 503:

```bash
# Check if backend service is reachable from gateway container
docker compose exec api-gateway curl http://user-nginx:80/api/health
```

### rdkafka extension missing:

```bash
# Check if extension is loaded
docker compose exec user-service php -m | grep rdkafka
# Should output: rdkafka

# If missing, rebuild the Docker image
docker compose build --no-cache
```

---

## Architecture Summary

```
┌─────────────┐
│   Client    │
└──────┬──────┘
       │ HTTP :8000
┌──────▼──────┐
│ API Gateway │ (Rate Limiting + Retry)
└──┬───┬───┬──┘
   │   │   │  HTTP (Docker network)
┌──▼┐ ┌▼──┐ ┌▼───┐
│User│ │Prod│ │Order│
│Svc │ │Svc │ │Svc  │
└─┬──┘ └─┬──┘ └─┬───┘
  │       │      │
┌─▼──┐ ┌─▼──┐ ┌─▼──┐
│ DB │ │ DB │ │ DB │  (PostgreSQL)
└────┘ └────┘ └────┘

Order Svc ──publish──▶ Kafka ──consume──▶ Product Svc
                    (order-events topic)
```

---

## You're Done! 🎉

Your Laravel microservices architecture is complete with:
- **4 services** (Gateway + 3 backend)
- **3 databases** (isolated PostgreSQL per service)
- **Kafka** for async event-driven communication
- **Rate limiting** at the gateway level
- **Resilient HTTP** with retry logic
- **Health checks** across all services
- **Stock management** via event-driven saga pattern
