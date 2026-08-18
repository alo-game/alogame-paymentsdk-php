<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Dto;

/**
 * $orderCode is THIS partner's own order reference — Alogame stores it and
 * sends it back unchanged in the paymentReceived call for this same order,
 * so it must be unique and durable on the partner's side (a primary key or
 * equivalent), not a display string.
 */
final class CreateOrderResult
{
    private function __construct(
        public readonly string $orderCode,
    ) {
    }

    public static function created(string $orderCode): self
    {
        return new self($orderCode);
    }
}
