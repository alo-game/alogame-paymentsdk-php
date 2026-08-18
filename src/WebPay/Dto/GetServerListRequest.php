<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Dto;

final class GetServerListRequest
{
    public function __construct(
        public readonly ?string $gameId = null,
    ) {
    }
}
