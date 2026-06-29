<?php

namespace App\Handlers;

use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Contracts\ConsumerMessage;
use Junges\Kafka\Contracts\Handler;
use Junges\Kafka\Contracts\MessageConsumer;

class OrderCreatedHandler implements Handler
{
    public function __invoke(ConsumerMessage $message, MessageConsumer $consumer): void
    {
        $body = $message->getBody();

        // Log if the event was made
        Log::info('Received Kafka message: ' . json_encode($body));


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