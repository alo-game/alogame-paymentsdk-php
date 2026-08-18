<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Dto;

/**
 * Carries BOTH order references so the hook can match on whichever one it
 * actually stored: $orderId is Alogame's own (from the earlier
 * CreateOrderRequest), $orderCode is the one this partner returned from
 * CreateOrderResult::created() for that same order.
 */
final class PaymentReceivedRequest
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $orderCode,
        public readonly int $amount,
        public readonly ?string $gameId = null,
    ) {
    }
}
