<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Dto;

/**
 * Private constructor + named factories rather than a public constructor —
 * `found: true` with no nickname, or `found: false` with one, are both
 * meaningless states this shape shouldn't be able to express.
 */
final class CheckUidResult
{
    private function __construct(
        public readonly bool $found,
        public readonly ?string $nickname = null,
        public readonly ?string $server = null,
    ) {
    }

    public static function found(string $nickname, ?string $server = null): self
    {
        return new self(true, $nickname, $server);
    }

    public static function notFound(): self
    {
        return new self(false);
    }
}
