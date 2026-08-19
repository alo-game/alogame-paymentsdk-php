<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Expub\Dto;

final class PaymentReceivedResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $orderCodeNotFound,
        public readonly bool $alreadyProcessed,
    ) {
    }

    /** Item delivered — respond with the exact envelope Alogame's dispatcher checks for. */
    public static function ok(): self
    {
        return new self(true, false, false);
    }

    public static function orderCodeNotFound(): self
    {
        return new self(false, true, false);
    }

    /**
     * Already delivered this order — Alogame treats this the same as ok()
     * for retry purposes (it stops retrying either way), but it gets its own
     * HTTP status so a partner's own logs can tell "delivered just now" apart
     * from "was already done when this retry arrived".
     */
    public static function alreadyProcessed(): self
    {
        return new self(false, false, true);
    }
}
