<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Dto;

final class ServerInfo
{
    public function __construct(
        public readonly string $serverId,
        public readonly string $name,
    ) {
    }
}
