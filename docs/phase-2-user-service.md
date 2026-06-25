# Phase 2: User Service

> Build the User microservice with full CRUD and Kafka event publishing.

---

## Prerequisites

- Phase 1 complete (Docker environment running)
- Composer available (either locally or via Docker container)

---

## Step 2.1 — Scaffold Laravel Project

```bash
# From project root
composer create-project laravel/laravel user-service

# OR via Docker if you don't have PHP/Composer locally:
docker compose run --rm user-service composer create-project laravel/laravel .
```

**Verify:** `user-service/` directory now contains a full Laravel 12 installation.

---

## Step 2.2 — Configure PostgreSQL Connection

### Edit `user-service/.env`:

```env
APP_NAME=UserService
APP_URL=http://localhost:8001

DB_CONNECTION=pgsql
DB_HOST=user-db
DB_PORT=5432
DB_DATABASE=user_service
DB_USERNAME=user_user
DB_PASSWORD=user_password

KAFKA_BROKERS=kafka:9092
```

### Verify `user-service/config/database.php`:

The `pgsql` connection should already be defined. Just make sure it reads from env:

```php
'pgsql' => [
    'driver' => 'pgsql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    // ...
],
```

---

## Step 2.3 — Create User Model + Migration

The default Laravel `User` model already exists. Update the migration to match your needs:

### Edit `user-service/database/migrations/0001_01_01_000000_create_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
```

### Update `user-service/app/Models/User.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
```

---

## Step 2.4 — Create UserController

### Create file: `user-service/app/Http/Controllers/UserController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::all();
        return response()->json(['data' => $users]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => $user]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create($validated);

        // Dispatch Kafka event (added in Step 2.9)
        // event(new \App\Events\UserCreated($user));

        return response()->json(['data' => $user], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:8',
        ]);

        $user->update($validated);

        return response()->json(['data' => $user]);
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return response()->json(['message' => 'User deleted'], 200);
    }
}
```

---

## Step 2.5 — Define API Routes

### Create/Edit `user-service/routes/api.php`:

```php
<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::apiResource('v1/users', UserController::class);
```

**Important:** Make sure the API routes are loaded. In Laravel 12, check `bootstrap/app.php`:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',    // ← ensure this line exists
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

If `api.php` doesn't exist, create it. The API routes will be prefixed with `/api/` automatically.

**Resulting endpoints:**
- `GET    /api/v1/users` → index
- `POST   /api/v1/users` → store
- `GET    /api/v1/users/{user}` → show
- `PUT    /api/v1/users/{user}` → update
- `DELETE /api/v1/users/{user}` → destroy

---

## Step 2.6 — Add Health Check Endpoint

### Add to `user-service/routes/api.php`:

```php
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'user-service',
        'timestamp' => now()->toISOString(),
    ]);
});
```

**Test:** `GET http://localhost:8001/api/health`

---

## Step 2.7 — Install Laravel Kafka Package

```bash
# Inside user-service directory
cd user-service
composer require mateusjunges/laravel-kafka
```

**If running via Docker:**
```bash
docker compose exec user-service composer require mateusjunges/laravel-kafka
```

---

## Step 2.8 — Create UserCreated Event

### Create file: `user-service/app/Events/UserCreated.php`

```php
<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $user
    ) {}
}
```

---

## Step 2.9 — Create Kafka Producer Listener

### Create file: `user-service/app/Listeners/PublishUserCreatedEvent.php`

```php
<?php

namespace App\Listeners;

use App\Events\UserCreated;
use Junges\Kafka\Facades\Kafka;
use Junges\Kafka\Message\Message;

class PublishUserCreatedEvent
{
    public function handle(UserCreated $event): void
    {
        $message = new Message(
            body: [
                'event' => 'user.created',
                'data' => [
                    'id' => $event->user->id,
                    'name' => $event->user->name,
                    'email' => $event->user->email,
                    'created_at' => $event->user->created_at->toISOString(),
                ],
            ]
        );

        Kafka::publish()
            ->onTopic('user-events')
            ->withMessage($message)
            ->send();
    }
}
```

### Register the listener in `user-service/app/Providers/AppServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Events\UserCreated;
use App\Listeners\PublishUserCreatedEvent;
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
            UserCreated::class,
            PublishUserCreatedEvent::class,
        );
    }
}
```

### Now uncomment the event dispatch in `UserController::store()`:

```php
public function store(Request $request): JsonResponse
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users,email',
        'password' => 'required|string|min:8',
    ]);

    $user = User::create($validated);

    event(new \App\Events\UserCreated($user));

    return response()->json(['data' => $user], 201);
}
```

---

## Step 2.10 — Publish Kafka Config

```bash
cd user-service
php artisan vendor:publish --tag=laravel-kafka-config
```

### Edit `user-service/config/kafka.php` — set the broker:

```php
'brokers' => env('KAFKA_BROKERS', 'kafka:9092'),
```

### Add to `user-service/.env`:

```env
KAFKA_BROKERS=kafka:9092
```

---

## Step 2.11 — Run Migrations & Test

### Run migrations:

```bash
docker compose exec user-service php artisan migrate
```

### Test endpoints with curl:

```bash
# Health check
curl http://localhost:8001/api/health

# Create a user
curl -X POST http://localhost:8001/api/v1/users \
  -H "Content-Type: application/json" \
  -d '{"name": "John Doe", "email": "john@example.com", "password": "password123"}'

# List all users
curl http://localhost:8001/api/v1/users

# Get a specific user
curl http://localhost:8001/api/v1/users/1

# Update a user
curl -X PUT http://localhost:8001/api/v1/users/1 \
  -H "Content-Type: application/json" \
  -d '{"name": "John Updated"}'

# Delete a user
curl -X DELETE http://localhost:8001/api/v1/users/1
```

### Verify Kafka event was published:

Open Kafka UI at `http://localhost:8080` → Topics → `user-events` → Messages

---

## ✅ Phase 2 Complete Checklist

- [ ] Laravel project scaffolded in `user-service/`
- [ ] PostgreSQL configured and connection works
- [ ] `User` model and migration ready
- [ ] `UserController` with all 5 CRUD methods
- [ ] API routes respond at `/api/v1/users`
- [ ] Health endpoint at `/api/health` works
- [ ] `mateusjunges/laravel-kafka` installed
- [ ] `UserCreated` event class created
- [ ] Kafka listener publishes to `user-events` topic
- [ ] Kafka config published and broker set
- [ ] Migrations run successfully
- [ ] All CRUD endpoints tested and working
- [ ] Kafka message visible in Kafka UI after creating a user

---

## File Structure After Phase 2

```
user-service/
├── app/
│   ├── Events/
│   │   └── UserCreated.php
│   ├── Http/
│   │   └── Controllers/
│   │       └── UserController.php
│   ├── Listeners/
│   │   └── PublishUserCreatedEvent.php
│   ├── Models/
│   │   └── User.php
│   └── Providers/
│       └── AppServiceProvider.php
├── config/
│   └── kafka.php
├── database/
│   └── migrations/
│       └── 0001_01_01_000000_create_users_table.php
├── routes/
│   └── api.php
├── .env
└── composer.json
```

---

**Next:** [Phase 3 — Product Service](./phase-3-product-service.md)
