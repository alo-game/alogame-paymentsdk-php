<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Expub\Dto;

/**
 * $orderCode is the reference this game returned from CreateOrderResult —
 * match on it to find which order to deliver. $orderId (Alogame's own
 * reference from the earlier onCreateOrder call) is carried too when Alogame
 * sends it, so a game that stores both can match on either.
 */
final class PaymentReceivedRequest
{
    public function __construct(
        public readonly string $orderCode,
        public readonly ?string $orderId = null,
        public readonly ?int $amount = null,
        public readonly ?string $extInfo = null,
    ) {
    }
}
