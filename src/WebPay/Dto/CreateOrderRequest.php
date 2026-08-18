<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Dto;

/**
 * $orderId is ALOGAME's own order reference (wire field `plat_order_num`) —
 * store it alongside whatever order id this partner creates for itself, so
 * a later paymentReceived call (which carries both) can be matched to the
 * same row unambiguously. $amount is VND, integer (no decimals).
 */
final class CreateOrderRequest
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $uid,
        public readonly string $productId,
        public readonly int $amount,
        public readonly bool $sandbox,
        public readonly ?string $serverId = null,
        public readonly ?string $gameId = null,
    ) {
    }
}
