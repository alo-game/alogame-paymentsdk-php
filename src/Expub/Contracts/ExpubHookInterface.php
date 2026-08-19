<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Expub\Contracts;

use Alogame\PaymentSdk\Expub\Dto\CheckUidRequest;
use Alogame\PaymentSdk\Expub\Dto\CheckUidResult;
use Alogame\PaymentSdk\Expub\Dto\CreateOrderRequest;
use Alogame\PaymentSdk\Expub\Dto\CreateOrderResult;
use Alogame\PaymentSdk\Expub\Dto\GetUserListRequest;
use Alogame\PaymentSdk\Expub\Dto\PaymentReceivedRequest;
use Alogame\PaymentSdk\Expub\Dto\PaymentReceivedResult;
use Alogame\PaymentSdk\Expub\Dto\UserCharacter;

/**
 * The only thing an expub game backend implements. All four calls are
 * ALWAYS initiated by Alogame — a player's top-up starts on the Alogame
 * Portal, never on this side. onCreateOrder/onPaymentReceived also serve
 * Mobile IAP purchases for this same game (see CreateOrderRequest::$osId) —
 * implement them once, not once per channel.
 *
 * Every call must be idempotent: Alogame retries onCreateOrder and
 * onPaymentReceived on timeout, so the same order may arrive more than once.
 */
interface ExpubHookInterface
{
    /**
     * Every character linked to this Alogame account, so the player can pick
     * one before topping up.
     *
     * @return UserCharacter[]
     */
    public function onGetUserList(GetUserListRequest $request): array;

    /** Does this character still exist? Called right before order creation. */
    public function onCheckUid(CheckUidRequest $request): CheckUidResult;

    /** Reserve the top-up server-side; return the order reference to remember for onPaymentReceived. */
    public function onCreateOrder(CreateOrderRequest $request): CreateOrderResult;

    /** Payment confirmed by Alogame — deliver the item now. */
    public function onPaymentReceived(PaymentReceivedRequest $request): PaymentReceivedResult;
}
