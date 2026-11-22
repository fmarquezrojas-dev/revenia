<?php

namespace App\Contracts;

use App\Services\PaymentReceiver;

interface PaymentGateway
{
    public function forReceiver(PaymentReceiver $receiver): self;

    public function create(object $order): array;

    public function recover(object $order): ?array;

    public function payment(string $id): array;

    public function paymentIds(object $order): array;
}
