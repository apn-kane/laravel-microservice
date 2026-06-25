# Phase 4: Order Service

> Build the Order microservice with CRUD, inter-service HTTP communication, and Kafka event publishing.

---

## Prerequisites

- Phase 1 complete (Docker environment running)
- Phase 2 & 3 complete (User Service & Product Service running)
- Kafka running and accessible at `kafka:9092`

---

## Step 4.1 — Scaffold Laravel Project

```bash
# From project root
composer create-project laravel/laravel order-service

# OR via Docker:
docker compose run --rm order-service composer create-project laravel/laravel .
```

---

## Step 4.2 — Configure PostgreSQL Connection

### Edit `order-service/.env`:

```env
APP_NAME=OrderService
APP_URL=http://localhost:8003

DB_CONNECTION=pgsql
DB_HOST=order-db
DB_PORT=5432
DB_DATABASE=order_service
DB_USERNAME=order_user
DB_PASSWORD=order_password

KAFKA_BROKERS=kafka:9092

# Internal service URLs (Docker network)
USER_SERVICE_URL=http://user-nginx:80
PRODUCT_SERVICE_URL=http://product-nginx:80
```

---

## Step 4.3 — Create Order Model + Migration

```bash
docker compose exec order-service php artisan make:model Order -m
```

### Edit `order-service/database/migrations/xxxx_xx_xx_create_orders_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('pending'); // pending, confirmed, cancelled
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
```

### Edit `order-service/app/Models/Order.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'status',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'user_id' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
```

---

## Step 4.4 — Create OrderItem Model + Migration

```bash
docker compose exec order-service php artisan make:model OrderItem -m
```

### Edit `order-service/database/migrations/xxxx_xx_xx_create_order_items_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('product_id');
            $table->integer('quantity');
            $table->decimal('price', 10, 2); // price at time of order
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
```

### Edit `order-service/app/Models/OrderItem.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'quantity' => 'integer',
            'product_id' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
```

---

## Step 4.5 — Relationships Summary

Already defined above:

- `Order` → `hasMany(OrderItem::class)` via `items()` method
- `OrderItem` → `belongsTo(Order::class)` via `order()` method

---

## Step 4.6 — Create OrderController

### Create file: `order-service/app/Http/Controllers/OrderController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Events\OrderCreated;
use App\Services\UserServiceClient;
use App\Services\ProductServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(
        private UserServiceClient $userClient,
        private ProductServiceClient $productClient,
    ) {}

    public function index(): JsonResponse
    {
        $orders = Order::with('items')->get();
        return response()->json(['data' => $orders]);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load('items');
        return response()->json(['data' => $order]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        // Validate user exists
        $user = $this->userClient->getUser($validated['user_id']);
        if (!$user) {
            return response()->json(['error' => 'User not found'], 422);
        }

        // Validate products exist and have sufficient stock
        $total = 0;
        $itemsWithPrice = [];

        foreach ($validated['items'] as $item) {
            $product = $this->productClient->getProduct($item['product_id']);

            if (!$product) {
                return response()->json([
                    'error' => "Product {$item['product_id']} not found"
                ], 422);
            }

            if ($product['stock'] < $item['quantity']) {
                return response()->json([
                    'error' => "Insufficient stock for product {$product['name']}. Available: {$product['stock']}"
                ], 422);
            }

            $itemsWithPrice[] = [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'price' => $product['price'],
            ];

            $total += $product['price'] * $item['quantity'];
        }

        // Create order in transaction
        $order = DB::transaction(function () use ($validated, $itemsWithPrice, $total) {
            $order = Order::create([
                'user_id' => $validated['user_id'],
                'status' => 'confirmed',
                'total' => $total,
            ]);

            foreach ($itemsWithPrice as $item) {
                $order->items()->create($item);
            }

            return $order;
        });

        $order->load('items');

        // Publish Kafka event
        event(new OrderCreated($order));

        return response()->json(['data' => $order], 201);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|string|in:pending,confirmed,cancelled',
        ]);

        $order->update($validated);

        return response()->json(['data' => $order]);
    }

    public function destroy(Order $order): JsonResponse
    {
        $order->delete();

        return response()->json(['message' => 'Order deleted'], 200);
    }
}
```

---

## Step 4.7 — Define API Routes

### Create/Edit `order-service/routes/api.php`:

```php
<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::apiResource('v1/orders', OrderController::class);

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'order-service',
        'timestamp' => now()->toISOString(),
    ]);
});
```

### Ensure API routing is enabled in `bootstrap/app.php`:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

---

## Step 4.8 — Health Check

Already added in Step 4.7. Test:

```bash
curl http://localhost:8003/api/health
```

---

## Step 4.9 — Create UserServiceClient

### Create file: `order-service/app/Services/UserServiceClient.php`

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UserServiceClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.user_service.url');
    }

    /**
     * Get a user by ID from the User Service.
     * Returns user data array or null if not found.
     */
    public function getUser(int $userId): ?array
    {
        try {
            $response = Http::timeout(5)
                ->retry(3, 100)
                ->get("{$this->baseUrl}/api/v1/users/{$userId}");

            if ($response->successful()) {
                return $response->json('data');
            }

            return null;
        } catch (\Exception $e) {
            Log::error("UserServiceClient error: {$e->getMessage()}");
            return null;
        }
    }
}
```

---

## Step 4.10 — Create ProductServiceClient

### Create file: `order-service/app/Services/ProductServiceClient.php`

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductServiceClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.product_service.url');
    }

    /**
     * Get a product by ID from the Product Service.
     * Returns product data array or null if not found.
     */
    public function getProduct(int $productId): ?array
    {
        try {
            $response = Http::timeout(5)
                ->retry(3, 100)
                ->get("{$this->baseUrl}/api/v1/products/{$productId}");

            if ($response->successful()) {
                return $response->json('data');
            }

            return null;
        } catch (\Exception $e) {
            Log::error("ProductServiceClient error: {$e->getMessage()}");
            return null;
        }
    }
}
```

### Configure service URLs in `order-service/config/services.php`:

Add these entries to the `return` array:

```php
'user_service' => [
    'url' => env('USER_SERVICE_URL', 'http://user-nginx:80'),
],

'product_service' => [
    'url' => env('PRODUCT_SERVICE_URL', 'http://product-nginx:80'),
],
```

---

## Step 4.11 — Install Laravel Kafka Package

```bash
docker compose exec order-service composer require mateusjunges/laravel-kafka
```

### Publish config:

```bash
docker compose exec order-service php artisan vendor:publish --tag=laravel-kafka-config
```

### Edit `order-service/config/kafka.php`:

```php
'brokers' => env('KAFKA_BROKERS', 'kafka:9092'),
```

---

## Step 4.12 — Create Kafka Producer (OrderCreated Event + Listener)

### Create file: `order-service/app/Events/OrderCreated.php`

```php
<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Order $order
    ) {}
}
```

### Create file: `order-service/app/Listeners/PublishOrderCreatedEvent.php`

```php
<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use Junges\Kafka\Facades\Kafka;
use Junges\Kafka\Message\Message;

class PublishOrderCreatedEvent
{
    public function handle(OrderCreated $event): void
    {
        $order = $event->order->load('items');

        $message = new Message(
            body: [
                'event' => 'order.created',
                'data' => [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'status' => $order->status,
                    'total' => $order->total,
                    'items' => $order->items->map(fn ($item) => [
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                    ])->toArray(),
                    'created_at' => $order->created_at->toISOString(),
                ],
            ]
        );

        Kafka::publish()
            ->onTopic('order-events')
            ->withMessage($message)
            ->send();
    }
}
```

### Register listener in `order-service/app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Events\OrderCreated;
use App\Listeners\PublishOrderCreatedEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::listen(
            OrderCreated::class,
            PublishOrderCreatedEvent::class,
        );
    }
}
```

---

## Step 4.13 — Run Migrations & Test

### Run migrations:

```bash
docker compose exec order-service php artisan migrate
```

### Test the full flow:

**1. Ensure User Service has a user:**
```bash
curl -X POST http://localhost:8001/api/v1/users \
  -H "Content-Type: application/json" \
  -d '{"name": "Jane Doe", "email": "jane@example.com", "password": "password123"}'
```

**2. Ensure Product Service has products:**
```bash
curl -X POST http://localhost:8002/api/v1/products \
  -H "Content-Type: application/json" \
  -d '{"name": "Laptop", "description": "Gaming laptop", "price": 999.99, "stock": 10}'
```

**3. Start the Product Service Kafka consumer** (in a separate terminal):
```bash
docker compose exec product-service php artisan kafka:consume-orders
```

**4. Create an order:**
```bash
curl -X POST http://localhost:8003/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{
    "user_id": 1,
    "items": [
      {"product_id": 1, "quantity": 2}
    ]
  }'
```

**5. Verify order was created:**
```bash
curl http://localhost:8003/api/v1/orders/1
```

**6. Verify product stock was decremented:**
```bash
curl http://localhost:8002/api/v1/products/1
# stock should now be 8 (was 10, decremented by 2)
```

**7. Check Kafka UI:**
Open `http://localhost:8080` → Topics → `order-events` → verify message exists

---

## ✅ Phase 4 Complete Checklist

- [ ] Laravel project scaffolded in `order-service/`
- [ ] PostgreSQL configured and connection works
- [ ] `Order` model and migration created
- [ ] `OrderItem` model and migration created
- [ ] Eloquent relationships defined (hasMany / belongsTo)
- [ ] `OrderController` with CRUD + validation logic
- [ ] API routes respond at `/api/v1/orders`
- [ ] Health endpoint at `/api/health` works
- [ ] `UserServiceClient` calls user-service to validate user exists
- [ ] `ProductServiceClient` calls product-service to validate products & stock
- [ ] `mateusjunges/laravel-kafka` installed and config published
- [ ] `OrderCreated` event + `PublishOrderCreatedEvent` listener
- [ ] Kafka message published to `order-events` topic on order creation
- [ ] Migrations run successfully
- [ ] End-to-end flow works: create order → Kafka → product stock decremented

---

## File Structure After Phase 4

```
order-service/
├── app/
│   ├── Events/
│   │   └── OrderCreated.php
│   ├── Http/
│   │   └── Controllers/
│   │       └── OrderController.php
│   ├── Listeners/
│   │   └── PublishOrderCreatedEvent.php
│   ├── Models/
│   │   ├── Order.php
│   │   └── OrderItem.php
│   ├── Providers/
│   │   └── AppServiceProvider.php
│   └── Services/
│       ├── ProductServiceClient.php
│       └── UserServiceClient.php
├── config/
│   ├── kafka.php
│   └── services.php
├── database/
│   └── migrations/
│       ├── xxxx_xx_xx_create_orders_table.php
│       └── xxxx_xx_xx_create_order_items_table.php
├── routes/
│   └── api.php
├── .env
└── composer.json
```

---

**Next:** [Phase 5 — API Gateway](./phase-5-api-gateway.md)
