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