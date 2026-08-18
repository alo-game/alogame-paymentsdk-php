<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Dto;

final class PaymentReceivedResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $reason = null,
    ) {
    }

    public static function ok(): self
    {
        return new self(true);
    }

    /**
     * Alogame retries automatically on a non-ok response (up to 3 times,
     * with backoff) — $reason is logged on the Alogame side for
     * troubleshooting, it never reaches the player.
     */
    public static function failed(?string $reason = null): self
    {
        return new self(false, $reason);
    }
}
