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