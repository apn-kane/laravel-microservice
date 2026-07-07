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