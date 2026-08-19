<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Expub\Dto;

/**
 * Confirms a character the player already picked from onGetUserList's result
 * still exists, right before Alogame generates an order for it.
 */
final class CheckUidRequest
{
    public function __construct(
        public readonly string $uid,
        public readonly ?string $extInfo = null,
    ) {
    }
}
