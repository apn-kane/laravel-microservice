# Phase 3: Product Service

> Build the Product microservice with CRUD and a Kafka consumer that decrements stock on order creation.

---

## Prerequisites

- Phase 1 complete (Docker environment running)
- Kafka running and accessible at `kafka:9092`

---

## Step 3.1 — Scaffold Laravel Project

```bash
# From project root
composer create-project laravel/laravel product-service

# OR via Docker:
docker compose run --rm product-service composer create-project laravel/laravel .
```

---

## Step 3.2 — Configure PostgreSQL Connection

### Edit `product-service/.env`:

```env
APP_NAME=ProductService
APP_URL=http://localhost:8002

DB_CONNECTION=pgsql
DB_HOST=product-db
DB_PORT=5432
DB_DATABASE=product_service
DB_USERNAME=product_user
DB_PASSWORD=product_password

KAFKA_BROKERS=kafka:9092
```

---

## Step 3.3 — Create Product Model + Migration

### Create migration:

```bash
docker compose exec product-service php artisan make:model Product -m
```

### Edit `product-service/database/migrations/xxxx_xx_xx_create_products_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->integer('stock')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
```

### Edit `product-service/app/Models/Product.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'stock',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock' => 'integer',
        ];
    }
}
```

---

## Step 3.4 — Create ProductController

### Create file: `product-service/app/Http/Controllers/ProductController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(): JsonResponse
    {
        $products = Product::all();
        return response()->json(['data' => $products]);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json(['data' => $product]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
        ]);

        $product = Product::create($validated);

        return response()->json(['data' => $product], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'price' => 'sometimes|numeric|min:0',
            'stock' => 'sometimes|integer|min:0',
        ]);

        $product->update($validated);

        return response()->json(['data' => $product]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(['message' => 'Product deleted'], 200);
    }
}
```

---

## Step 3.5 — Define API Routes

### Create/Edit `product-service/routes/api.php`:

```php
<?php

use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::apiResource('v1/products', ProductController::class);

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'product-service',
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

**Resulting endpoints:**
- `GET    /api/v1/products` → index
- `POST   /api/v1/products` → store
- `GET    /api/v1/products/{product}` → show
- `PUT    /api/v1/products/{product}` → update
- `DELETE /api/v1/products/{product}` → destroy
- `GET    /api/health` → health check

---

## Step 3.6 — Health Check

Already added in Step 3.5 above. Test with:

```bash
curl http://localhost:8002/api/health
```

---

## Step 3.7 — Install Laravel Kafka Package

```bash
docker compose exec product-service composer require mateusjunges/laravel-kafka
```

### Publish config:

```bash
docker compose exec product-service php artisan vendor:publish --tag=laravel-kafka-config
```

### Edit `product-service/config/kafka.php`:

```php
'brokers' => env('KAFKA_BROKERS', 'kafka:9092'),
```

---

## Step 3.8 — Create Kafka Consumer Command

### Create file: `product-service/app/Console/Commands/ConsumeOrderEvents.php`

```php
<?php

namespace App\Console\Commands;

use App\Handlers\OrderCreatedHandler;
use Illuminate\Console\Command;
use Junges\Kafka\Facades\Kafka;

class ConsumeOrderEvents extends Command
{
    protected $signature = 'kafka:consume-orders';
    protected $description = 'Consume order.created events from Kafka';

    public function handle(): void
    {
        $this->info('Starting Kafka consumer for order-events topic...');

        $consumer = Kafka::consumer(['order-events'])
            ->withBrokers(config('kafka.brokers'))
            ->withGroupId('product-service-group')
            ->withHandler(new OrderCreatedHandler())
            ->build();

        $consumer->consume();
    }
}
```

**How to run:** This command runs as a long-lived process inside the container:

```bash
docker compose exec product-service php artisan kafka:consume-orders
```

**Tip:** In production, you'd use Supervisor or a dedicated container to keep this running. For development, run it in a separate terminal.

---

## Step 3.9 — Create OrderCreatedHandler

### Create file: `product-service/app/Handlers/OrderCreatedHandler.php`

```php
<?php

namespace App\Handlers;

use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\MessageConsumer;
use Junges\Kafka\Contracts\KafkaConsumerMessage;

class OrderCreatedHandler implements MessageConsumer
{
    public function handle(KafkaConsumerMessage $message): void
    {
        $body = $message->getBody();

        if (($body['event'] ?? null) !== 'order.created') {
            return;
        }

        $items = $body['data']['items'] ?? [];

        foreach ($items as $item) {
            $product = Product::find($item['product_id']);

            if (!$product) {
                Log::warning("Product not found: {$item['product_id']}");
                continue;
            }

            if ($product->stock < $item['quantity']) {
                Log::warning("Insufficient stock for product {$product->id}. Requested: {$item['quantity']}, Available: {$product->stock}");
                continue;
            }

            $product->decrement('stock', $item['quantity']);

            Log::info("Decremented stock for product {$product->id} by {$item['quantity']}. New stock: {$product->fresh()->stock}");
        }
    }
}
```

**Expected Kafka message format** (published by Order Service in Phase 4):

```json
{
    "event": "order.created",
    "data": {
        "order_id": 1,
        "user_id": 1,
        "items": [
            { "product_id": 1, "quantity": 2, "price": "29.99" },
            { "product_id": 3, "quantity": 1, "price": "49.99" }
        ]
    }
}
```

---

## Step 3.10 — Run Migrations & Test

### Run migrations:

```bash
docker compose exec product-service php artisan migrate
```

### Test CRUD endpoints:

```bash
# Health check
curl http://localhost:8002/api/health

# Create a product
curl -X POST http://localhost:8002/api/v1/products \
  -H "Content-Type: application/json" \
  -d '{"name": "Wireless Mouse", "description": "Ergonomic wireless mouse", "price": 29.99, "stock": 100}'

# Create another product
curl -X POST http://localhost:8002/api/v1/products \
  -H "Content-Type: application/json" \
  -d '{"name": "Mechanical Keyboard", "description": "RGB mechanical keyboard", "price": 89.99, "stock": 50}'

# List products
curl http://localhost:8002/api/v1/products

# Get specific product
curl http://localhost:8002/api/v1/products/1

# Update product
curl -X PUT http://localhost:8002/api/v1/products/1 \
  -H "Content-Type: application/json" \
  -d '{"price": 24.99}'

# Delete product
curl -X DELETE http://localhost:8002/api/v1/products/2
```

### Test Kafka consumer (manual test):

```bash
# In terminal 1: start the consumer
docker compose exec product-service php artisan kafka:consume-orders

# In terminal 2: produce a test message via Kafka CLI
docker compose exec kafka kafka-console-producer --broker-list localhost:9092 --topic order-events

# Then paste this JSON and press Enter:
{"event":"order.created","data":{"order_id":1,"user_id":1,"items":[{"product_id":1,"quantity":5,"price":"29.99"}]}}

# Check product stock was decremented
curl http://localhost:8002/api/v1/products/1
# stock should be 95 (was 100, decremented by 5)
```

---

## ✅ Phase 3 Complete Checklist

- [ ] Laravel project scaffolded in `product-service/`
- [ ] PostgreSQL configured and connection works
- [ ] `Product` model and migration created
- [ ] `ProductController` with all 5 CRUD methods
- [ ] API routes respond at `/api/v1/products`
- [ ] Health endpoint at `/api/health` works
- [ ] `mateusjunges/laravel-kafka` installed and config published
- [ ] `ConsumeOrderEvents` artisan command created
- [ ] `OrderCreatedHandler` decrements stock correctly
- [ ] Migrations run successfully
- [ ] All CRUD endpoints tested
- [ ] Kafka consumer correctly processes `order.created` events

---

## File Structure After Phase 3

```
product-service/
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       └── ConsumeOrderEvents.php
│   ├── Handlers/
│   │   └── OrderCreatedHandler.php
│   ├── Http/
│   │   └── Controllers/
│   │       └── ProductController.php
│   └── Models/
│       └── Product.php
├── config/
│   └── kafka.php
├── database/
│   └── migrations/
│       └── xxxx_xx_xx_create_products_table.php
├── routes/
│   └── api.php
├── .env
└── composer.json
```

---

**Next:** [Phase 4 — Order Service](./phase-4-order-service.md)
