<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Dto;

/**
 * $gameId is NOT Alogame's platform enum — it's whatever store identifier
 * this partner itself configured in Console (platformGameIds), only present
 * for a partner that runs iOS/Android as two separate games server-side.
 * Every other partner never sees it (null).
 */
final class CheckUidRequest
{
    public function __construct(
        public readonly string $uid,
        public readonly ?string $serverId = null,
        public readonly ?string $gameId = null,
    ) {
    }
}
