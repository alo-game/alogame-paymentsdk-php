<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Expub\Dto;

/**
 * Unlike WebPay's CheckUidResult, this contract signals "not found" via
 * HTTP 404, not a 200 body with an error code — the expub 4-API contract
 * reports every error through the HTTP status, never a body-level field.
 */
final class CheckUidResult
{
    private function __construct(
        public readonly bool $found,
        public readonly ?string $characterName = null,
        public readonly ?string $server = null,
    ) {
    }

    public static function found(string $characterName, ?string $server = null): self
    {
        return new self(true, $characterName, $server);
    }

    public static function notFound(): self
    {
        return new self(false);
    }
}
