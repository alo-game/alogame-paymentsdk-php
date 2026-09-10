<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Dto;

/**
 * $characterId is what Alogame sends back as `character_id` on the next
 * createOrder call, so it has to be a value this backend can resolve to a
 * character on its own — not a display-only label.
 */
final class CharacterInfo
{
    public function __construct(
        public readonly string $characterId,
        public readonly string $name,
    ) {
    }
}
