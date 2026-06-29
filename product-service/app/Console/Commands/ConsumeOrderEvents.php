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
            ->withConsumerGroupId('product-service-group')
            ->withHandler(new OrderCreatedHandler())
            ->build();

        $consumer->consume();
    }
}