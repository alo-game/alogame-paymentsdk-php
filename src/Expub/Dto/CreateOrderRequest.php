<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Expub\Dto;

/**
 * $orderId is ALOGAME's own order reference (wire field `order_id`) — store
 * it alongside whatever order id this game creates for itself, so a later
 * onPaymentReceived call (which carries both) can be matched to the same
 * row unambiguously. $amount is VND (wire field `price`).
 *
 * This same call also delivers Mobile IAP purchases for an expub game — the
 * two channels share `createOrder_url`/`exchange_url` entirely, distinguished
 * only by $osId ("ios"/"android" for an in-app purchase, null for a web
 * top-up). Implement one onCreateOrder that branches on it rather than two
 * separate handlers.
 */
final class CreateOrderRequest
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $uid,
        public readonly string $productId,
        public readonly ?int $amount = null,
        public readonly ?string $serverId = null,
        public readonly ?string $osId = null,
        public readonly ?string $extInfo = null,
    ) {
    }
}
