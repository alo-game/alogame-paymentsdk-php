<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Contracts;

use Alogame\PaymentSdk\WebPay\Dto\GetServerListRequest;
use Alogame\PaymentSdk\WebPay\Dto\ServerInfo;

/**
 * Separate from WebpayHookInterface on purpose: most games have no server
 * concept and shouldn't be forced to implement a method that always
 * returns an empty array. Implement this ONLY if your game runs multiple
 * servers and the player must pick one before topping up — Alogame derives
 * "does this game need a server picker" from whether your hooks object
 * implements this interface, not from a config flag.
 */
interface ServerListHookInterface
{
    /**
     * @return ServerInfo[]
     */
    public function onGetServerList(GetServerListRequest $request): array;
}
