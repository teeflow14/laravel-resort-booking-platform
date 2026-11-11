<?php

// ============================================================
// app/Services/Payment/GatewayInterface.php
// ============================================================

namespace App\Services\Payment;

use App\Models\Booking;

interface GatewayInterface
{
    public function initiate(Booking $booking, array $payload): array;
    public function handleCallback(array $payload): array;
}

// end app/Services/Payment/GatewayInterface.php
