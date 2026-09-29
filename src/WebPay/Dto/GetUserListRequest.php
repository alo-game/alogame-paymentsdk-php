<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Dto;

/**
 * $userId is the Alogame account's numeric id — the same `userId` the
 * Alogame client SDK hands your game at login, so it's what your backend
 * links characters to. Never a uuid, never anything this game issued.
 */
final class GetUserListRequest
{
    public function __construct(
        public readonly string $userId,
    ) {
    }
}
