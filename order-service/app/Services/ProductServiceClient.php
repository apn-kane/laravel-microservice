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