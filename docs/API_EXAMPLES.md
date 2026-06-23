# API Examples — Sample Requests & Data

> Use these examples with `curl`, Postman, or any HTTP client.
> All requests go through the **API Gateway** at `http://localhost:8080`.

---

## 1. Health Checks

```bash
# Gateway health
curl http://localhost:8080/health

# Direct service health (internal ports)
curl http://localhost:8001/health   # User Service
curl http://localhost:8002/health   # Order Service
curl http://localhost:8003/health   # Product Service
```

**Expected Response:**
```json
{
  "status": "healthy",
  "service": "user-service",
  "timestamp": "2026-06-23T10:00:00+00:00"
}
```

---

## 2. User Service

### Create a User

```bash
curl -X POST http://localhost:8080/api/users/api/v1/users \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Juan Dela Cruz",
    "email": "juan@example.com",
    "password": "password123"
  }'
```

**Response (201):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Juan Dela Cruz",
    "email": "juan@example.com",
    "status": "active",
    "created_at": "2026-06-23T10:00:00.000000Z",
    "updated_at": "2026-06-23T10:00:00.000000Z"
  },
  "message": "User created successfully"
}
```

### Create More Users (Bulk Testing)

```bash
# User 2
curl -X POST http://localhost:8080/api/users/api/v1/users \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Maria Santos",
    "email": "maria@example.com",
    "password": "securepass456"
  }'

# User 3
curl -X POST http://localhost:8080/api/users/api/v1/users \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Pedro Reyes",
    "email": "pedro@example.com",
    "password": "mypassword789"
  }'
```

### List All Users

```bash
curl http://localhost:8080/api/users/api/v1/users
curl http://localhost:8080/api/users/api/v1/users?per_page=10
```

### Get Single User

```bash
curl http://localhost:8080/api/users/api/v1/users/1
```

### Update a User

```bash
curl -X PUT http://localhost:8080/api/users/api/v1/users/1 \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Juan D. Cruz Jr."
  }'
```

### Delete a User

```bash
curl -X DELETE http://localhost:8080/api/users/api/v1/users/1
```

---

## 3. Product Service

### Create Products

```bash
# Product 1
curl -X POST http://localhost:8080/api/products/api/v1/products \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Mechanical Keyboard",
    "description": "RGB mechanical keyboard with Cherry MX switches",
    "price": 2500.00,
    "stock": 50
  }'

# Product 2
curl -X POST http://localhost:8080/api/products/api/v1/products \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Wireless Mouse",
    "description": "Ergonomic wireless mouse with USB-C charging",
    "price": 1200.00,
    "stock": 100
  }'

# Product 3
curl -X POST http://localhost:8080/api/products/api/v1/products \
  -H "Content-Type: application/json" \
  -d '{
    "name": "USB-C Hub",
    "description": "7-in-1 USB-C hub with HDMI, USB 3.0, SD card reader",
    "price": 1800.00,
    "stock": 30
  }'

# Product 4
curl -X POST http://localhost:8080/api/products/api/v1/products \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Monitor Stand",
    "description": "Adjustable aluminum monitor stand with cable management",
    "price": 950.00,
    "stock": 75
  }'

# Product 5
curl -X POST http://localhost:8080/api/products/api/v1/products \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Webcam HD",
    "description": "1080p webcam with built-in microphone and auto-focus",
    "price": 1500.00,
    "stock": 40
  }'
```

### List All Products

```bash
curl http://localhost:8080/api/products/api/v1/products
```

### Get Single Product

```bash
curl http://localhost:8080/api/products/api/v1/products/1
```

### Update a Product

```bash
curl -X PUT http://localhost:8080/api/products/api/v1/products/1 \
  -H "Content-Type: application/json" \
  -d '{
    "price": 2200.00,
    "stock": 45
  }'
```

### Delete a Product

```bash
curl -X DELETE http://localhost:8080/api/products/api/v1/products/3
```

---

## 4. Order Service

### Create an Order (Triggers `order.created` Event)

```bash
curl -X POST http://localhost:8080/api/orders/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 1,
    "items": [
      { "product_id": 1, "quantity": 2 },
      { "product_id": 2, "quantity": 1 }
    ]
  }'
```

**Response (201):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "user_id": 1,
    "status": "pending",
    "total": 6200.00,
    "items": [
      { "product_id": 1, "quantity": 2, "price": 2500.00 },
      { "product_id": 2, "quantity": 1, "price": 1200.00 }
    ],
    "created_at": "2026-06-23T10:05:00.000000Z"
  },
  "message": "Order created successfully"
}
```

**Event Side Effect:** After this order, the Product Service will consume the `order.created` event and:
- Mechanical Keyboard stock: 50 → 48 (-2)
- Wireless Mouse stock: 100 → 99 (-1)

### Create More Orders

```bash
# Order 2 — different user, different products
curl -X POST http://localhost:8080/api/orders/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 2,
    "items": [
      { "product_id": 3, "quantity": 1 },
      { "product_id": 4, "quantity": 2 },
      { "product_id": 5, "quantity": 1 }
    ]
  }'

# Order 3 — single item order
curl -X POST http://localhost:8080/api/orders/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 3,
    "items": [
      { "product_id": 1, "quantity": 1 }
    ]
  }'
```

### List All Orders

```bash
curl http://localhost:8080/api/orders/api/v1/orders
curl http://localhost:8080/api/orders/api/v1/orders?user_id=1
```

### Get Single Order

```bash
curl http://localhost:8080/api/orders/api/v1/orders/1
```

### Update Order Status

```bash
curl -X PUT http://localhost:8080/api/orders/api/v1/orders/1 \
  -H "Content-Type: application/json" \
  -d '{
    "status": "confirmed"
  }'
```

### Cancel an Order

```bash
curl -X DELETE http://localhost:8080/api/orders/api/v1/orders/1
```

---

## 5. Verify Event-Driven Flow

After creating an order, verify the stock was decremented:

```bash
# Step 1: Check initial stock
curl http://localhost:8080/api/products/api/v1/products/1
# → stock: 50

# Step 2: Create order with 2x Product #1
curl -X POST http://localhost:8080/api/orders/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 1,
    "items": [{ "product_id": 1, "quantity": 2 }]
  }'

# Step 3: Check stock again (wait a second for event processing)
curl http://localhost:8080/api/products/api/v1/products/1
# → stock: 48  ✓ Event consumed successfully
```

---

## 6. RabbitMQ Management

Access the RabbitMQ dashboard to monitor events:

```
URL:      http://localhost:15672
Username: guest
Password: guest
```

You can see:
- **Exchanges:** `microservices` (topic exchange)
- **Queues:** `product-service-events`
- **Message rates** and delivery confirmations

---

## Error Responses

### Validation Error (422)
```json
{
  "success": false,
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password field must be at least 8 characters."]
  }
}
```

### Not Found (404)
```json
{
  "success": false,
  "message": "User not found"
}
```

### Service Unavailable (503)
```json
{
  "success": false,
  "message": "Service unavailable"
}
```
