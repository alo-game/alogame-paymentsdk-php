<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Expub\Dto;

/**
 * Four outcomes, not two — unlike WebPay's create_order (a single success
 * envelope handles a retried duplicate too), the expub 4-API contract gives
 * a duplicate order_id its own HTTP status, and reports a bad product/uid
 * as distinct error codes rather than a single generic failure.
 */
final class CreateOrderResult
{
    private function __construct(
        public readonly bool $created,
        public readonly bool $duplicate,
        public readonly bool $productNotFound,
        public readonly bool $uidNotFound,
        public readonly ?string $orderCode = null,
    ) {
    }

    public static function created(string $orderCode): self
    {
        return new self(true, false, false, false, $orderCode);
    }

    /**
     * Alogame retried create_order (network hiccup, timeout) and the same
     * $request->orderId arrived twice — return the SAME $orderCode you
     * returned the first time, never a new one.
     */
    public static function duplicate(string $orderCode): self
    {
        return new self(false, true, false, false, $orderCode);
    }

    public static function productNotFound(): self
    {
        return new self(false, false, true, false);
    }

    public static function uidNotFound(): self
    {
        return new self(false, false, false, true);
    }
}
