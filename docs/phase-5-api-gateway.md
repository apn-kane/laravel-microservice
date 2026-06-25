# Phase 5: API Gateway

> Build the API Gateway that proxies all client requests to the appropriate backend microservices with rate limiting and retry logic.

---

## Prerequisites

- Phase 1 complete (Docker environment running)
- Phases 2-4 complete (all backend services running)
- `api-gateway/` Laravel project already exists (scaffolded earlier)

---

## Step 5.1 — Configure the Existing API Gateway Project

The `api-gateway/` project already exists. Ensure it's properly configured.

### Edit `api-gateway/.env`:

```env
APP_NAME=ApiGateway
APP_URL=http://localhost:8000

# No database needed for the gateway (optional — remove DB vars or set to sqlite)
DB_CONNECTION=sqlite

# Internal service URLs (Docker service names)
USER_SERVICE_URL=http://user-nginx:80
PRODUCT_SERVICE_URL=http://product-nginx:80
ORDER_SERVICE_URL=http://order-nginx:80
```

### Edit `api-gateway/config/services.php`:

Add service URL configuration to the return array:

```php
<?php

return [

    // ... existing entries (mailgun, ses, etc.) ...

    'user_service' => [
        'url' => env('USER_SERVICE_URL', 'http://user-nginx:80'),
    ],

    'product_service' => [
        'url' => env('PRODUCT_SERVICE_URL', 'http://product-nginx:80'),
    ],

    'order_service' => [
        'url' => env('ORDER_SERVICE_URL', 'http://order-nginx:80'),
    ],

];
```

---

## Step 5.2 — Create Resilient HttpClient Service

### Create file: `api-gateway/app/Services/HttpClient.php`

```php
<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpClient
{
    /**
     * Send a resilient HTTP request with retry logic.
     *
     * @param string $method  HTTP method (GET, POST, PUT, DELETE, PATCH)
     * @param string $url     Full URL to the backend service
     * @param array  $data    Request body (for POST/PUT/PATCH)
     * @param array  $headers Additional headers to forward
     * @param int    $timeout Request timeout in seconds
     * @return Response
     */
    public function request(
        string $method,
        string $url,
        array $data = [],
        array $headers = [],
        int $timeout = 10,
    ): Response {
        $pendingRequest = Http::timeout($timeout)
            ->retry(3, 200, function (\Exception $exception) {
                // Only retry on connection errors or 5xx responses
                return $exception instanceof \Illuminate\Http\Client\ConnectionException
                    || ($exception instanceof \Illuminate\Http\Client\RequestException
                        && $exception->response->serverError());
            })
            ->withHeaders($headers);

        return match (strtoupper($method)) {
            'GET' => $pendingRequest->get($url, $data),
            'POST' => $pendingRequest->post($url, $data),
            'PUT' => $pendingRequest->put($url, $data),
            'PATCH' => $pendingRequest->patch($url, $data),
            'DELETE' => $pendingRequest->delete($url, $data),
            default => $pendingRequest->get($url),
        };
    }

    /**
     * Forward the current request to a backend service.
     */
    public function forward(string $baseUrl, string $path, string $method, array $data = [], array $headers = []): Response
    {
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

        Log::debug("Gateway forwarding: {$method} {$url}");

        return $this->request($method, $url, $data, $headers);
    }
}
```

---

## Step 5.3 — Create GatewayController

### Create file: `api-gateway/app/Http/Controllers/GatewayController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Services\HttpClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GatewayController extends Controller
{
    public function __construct(
        private HttpClient $httpClient,
    ) {}

    /**
     * Proxy requests to User Service.
     */
    public function users(Request $request, ?string $path = null): JsonResponse
    {
        return $this->proxy($request, 'user_service', 'api/v1/users' . ($path ? "/{$path}" : ''));
    }

    /**
     * Proxy requests to Product Service.
     */
    public function products(Request $request, ?string $path = null): JsonResponse
    {
        return $this->proxy($request, 'product_service', 'api/v1/products' . ($path ? "/{$path}" : ''));
    }

    /**
     * Proxy requests to Order Service.
     */
    public function orders(Request $request, ?string $path = null): JsonResponse
    {
        return $this->proxy($request, 'order_service', 'api/v1/orders' . ($path ? "/{$path}" : ''));
    }

    /**
     * Forward the request to the specified backend service.
     */
    private function proxy(Request $request, string $service, string $path): JsonResponse
    {
        $baseUrl = config("services.{$service}.url");

        if (!$baseUrl) {
            return response()->json(['error' => 'Service not configured'], 503);
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        try {
            $response = $this->httpClient->forward(
                baseUrl: $baseUrl,
                path: $path,
                method: $request->method(),
                data: $request->all(),
                headers: $headers,
            );

            return response()->json(
                $response->json(),
                $response->status()
            );
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json([
                'error' => 'Service unavailable',
                'service' => $service,
            ], 503);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Gateway error',
                'message' => $e->getMessage(),
            ], 502);
        }
    }
}
```

---

## Step 5.4 — Create RateLimitMiddleware

### Create file: `api-gateway/app/Http/Middleware/RateLimitMiddleware.php`

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = 'api:' . ($request->ip() ?? 'unknown');
        $maxAttempts = 60; // 60 requests per minute

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'error' => 'Too many requests',
                'retry_after' => $retryAfter,
            ], 429)->withHeaders([
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $maxAttempts,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, 60); // Decay in 60 seconds

        $response = $next($request);

        return $response->withHeaders([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => RateLimiter::remaining($key, $maxAttempts),
        ]);
    }
}
```

### Register middleware in `api-gateway/bootstrap/app.php`:

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(append: [
            \App\Http\Middleware\RateLimitMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
```

---

## Step 5.5 — Define Gateway Routes

### Create/Edit `api-gateway/routes/api.php`:

```php
<?php

use App\Http\Controllers\GatewayController;
use Illuminate\Support\Facades\Route;

// Health check
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'api-gateway',
        'timestamp' => now()->toISOString(),
    ]);
});

// User Service proxy
Route::match(['get', 'post'], '/v1/users', [GatewayController::class, 'users']);
Route::match(['get', 'put', 'patch', 'delete'], '/v1/users/{path}', [GatewayController::class, 'users'])
    ->where('path', '.*');

// Product Service proxy
Route::match(['get', 'post'], '/v1/products', [GatewayController::class, 'products']);
Route::match(['get', 'put', 'patch', 'delete'], '/v1/products/{path}', [GatewayController::class, 'products'])
    ->where('path', '.*');

// Order Service proxy
Route::match(['get', 'post'], '/v1/orders', [GatewayController::class, 'orders']);
Route::match(['get', 'put', 'patch', 'delete'], '/v1/orders/{path}', [GatewayController::class, 'orders'])
    ->where('path', '.*');
```

**Resulting gateway endpoints:**

| Gateway URL | Forwards To |
|---|---|
| `GET /api/v1/users` | user-service `/api/v1/users` |
| `POST /api/v1/users` | user-service `/api/v1/users` |
| `GET /api/v1/users/1` | user-service `/api/v1/users/1` |
| `PUT /api/v1/users/1` | user-service `/api/v1/users/1` |
| `DELETE /api/v1/users/1` | user-service `/api/v1/users/1` |
| `GET /api/v1/products` | product-service `/api/v1/products` |
| `POST /api/v1/orders` | order-service `/api/v1/orders` |
| ... | ... |

---

## Step 5.6 — Service URLs Configuration

Already done in Step 5.1. Verify `config/services.php` has:

```php
'user_service' => [
    'url' => env('USER_SERVICE_URL', 'http://user-nginx:80'),
],
'product_service' => [
    'url' => env('PRODUCT_SERVICE_URL', 'http://product-nginx:80'),
],
'order_service' => [
    'url' => env('ORDER_SERVICE_URL', 'http://order-nginx:80'),
],
```

---

## Step 5.7 — Enhanced Health Check (with downstream checks)

Optionally replace the simple health check with one that also checks downstream services:

### Create file: `api-gateway/app/Http/Controllers/HealthController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Services\HttpClient;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __construct(
        private HttpClient $httpClient,
    ) {}

    public function __invoke(): JsonResponse
    {
        $services = [
            'user-service' => config('services.user_service.url') . '/api/health',
            'product-service' => config('services.product_service.url') . '/api/health',
            'order-service' => config('services.order_service.url') . '/api/health',
        ];

        $results = [];
        $allHealthy = true;

        foreach ($services as $name => $url) {
            try {
                $response = $this->httpClient->request('GET', $url, timeout: 3);
                $results[$name] = $response->successful() ? 'healthy' : 'unhealthy';
            } catch (\Exception $e) {
                $results[$name] = 'unreachable';
                $allHealthy = false;
            }
        }

        return response()->json([
            'status' => $allHealthy ? 'ok' : 'degraded',
            'service' => 'api-gateway',
            'timestamp' => now()->toISOString(),
            'downstream' => $results,
        ], $allHealthy ? 200 : 207);
    }
}
```

### Update route in `routes/api.php`:

```php
use App\Http\Controllers\HealthController;

Route::get('/health', HealthController::class);
```

---

## Step 5.8 — Test End-to-End

### Start all services:

```bash
docker compose up -d
```

### Test through the gateway:

```bash
# Health check (gateway + downstream)
curl http://localhost:8000/api/health

# Create user through gateway
curl -X POST http://localhost:8000/api/v1/users \
  -H "Content-Type: application/json" \
  -d '{"name": "Gateway User", "email": "gateway@test.com", "password": "password123"}'

# List users through gateway
curl http://localhost:8000/api/v1/users

# Create product through gateway
curl -X POST http://localhost:8000/api/v1/products \
  -H "Content-Type: application/json" \
  -d '{"name": "Test Product", "description": "Via gateway", "price": 49.99, "stock": 25}'

# Create order through gateway (validates user + product via inter-service calls)
curl -X POST http://localhost:8000/api/v1/orders \
  -H "Content-Type: application/json" \
  -d '{"user_id": 1, "items": [{"product_id": 1, "quantity": 3}]}'

# Verify order
curl http://localhost:8000/api/v1/orders

# Verify product stock decremented
curl http://localhost:8000/api/v1/products/1
```

### Test rate limiting:

```bash
# Rapid fire requests (should get 429 after 60)
for i in $(seq 1 65); do
  echo "Request $i: $(curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/api/v1/users)"
done
```

---

## ✅ Phase 5 Complete Checklist

- [ ] `api-gateway/.env` configured with service URLs
- [ ] `config/services.php` has all 3 service URLs
- [ ] `HttpClient` service with retry logic (3 retries, exponential backoff)
- [ ] `GatewayController` proxies to correct backend services
- [ ] `RateLimitMiddleware` limits to 60 req/min per IP
- [ ] Middleware registered in `bootstrap/app.php`
- [ ] Gateway routes defined for users, products, orders
- [ ] `HealthController` checks downstream services
- [ ] End-to-end: Client → Gateway → Service → DB works
- [ ] Rate limiting returns 429 after threshold
- [ ] Error handling returns 503 when backend is down

---

## File Structure After Phase 5

```
api-gateway/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── GatewayController.php
│   │   │   └── HealthController.php
│   │   └── Middleware/
│   │       └── RateLimitMiddleware.php
│   └── Services/
│       └── HttpClient.php
├── bootstrap/
│   └── app.php
├── config/
│   └── services.php
├── routes/
│   └── api.php
└── .env
```

---

**Next:** [Phase 6 — Integration & Testing](./phase-6-integration-testing.md)
