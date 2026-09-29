<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Dto;

/**
 * One character linked to the Alogame account asked about in
 * GetUserListRequest. $uid is what Alogame sends back as `uid` on the
 * checkUid/createOrder calls that follow, so it has to be a value this
 * backend can resolve on its own — not a display-only label.
 */
final class UserCharacter
{
    public function __construct(
        public readonly string $uid,
        public readonly string $characterName,
        public readonly ?string $server = null,
    ) {
    }
}
