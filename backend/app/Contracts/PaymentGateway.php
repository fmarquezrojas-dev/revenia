<?php

namespace App\Contracts;

interface PaymentGateway
{
    public function create(object $order): array;

    public function recover(object $order): ?array;

    public function payment(string $id): array;

    public function paymentIds(object $order): array;
}
