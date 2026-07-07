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