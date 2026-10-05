<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;

class FirebaseNotificationService
{
    public function sendToToken(
        string $token,
        string $title,
        string $body,
        array $data = []
    ): void {
        $factory = (new Factory)
            ->withServiceAccount(config('services.firebase.credentials'));

        $messaging = $factory->createMessaging();

        $message = CloudMessage::withTarget('token', $token)
            ->withNotification(
                FirebaseNotification::create($title, $body)
            )
            ->withData(
                collect($data)
                    ->map(fn ($value) => (string) $value)
                    ->toArray()
            );

        $messaging->send($message);
    }
}
