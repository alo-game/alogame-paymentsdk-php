<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Expub\Contracts;

use Alogame\PaymentSdk\Expub\Dto\CreateOrderRequest;
use Alogame\PaymentSdk\Expub\Dto\CreateOrderResult;
use Alogame\PaymentSdk\Expub\Dto\GetUserListRequest;
use Alogame\PaymentSdk\Expub\Dto\PaymentReceivedRequest;
use Alogame\PaymentSdk\Expub\Dto\PaymentReceivedResult;
use Alogame\PaymentSdk\Expub\Dto\UserCharacter;

/**
 * The only thing an expub game backend implements. All three calls are
 * ALWAYS initiated by Alogame — a player's top-up starts on the Alogame
 * Portal, never on this side. onCreateOrder/onPaymentReceived also serve
 * Mobile IAP purchases for this same game (see CreateOrderRequest::$osId) —
 * implement them once, not once per channel.
 *
 * "Does this uid still exist" is NOT one of the three required calls: an
 * expub player always reaches checkout by logging into Alogame and picking
 * a character from onGetUserList's own response, so uid existence is
 * already proven by construction — unlike WebPay's co-pub flow, where the
 * player types a UID by hand and \Alogame\PaymentSdk\WebPay\Contracts\
 * WebpayHookInterface::onCheckUid() is the only thing that can validate it.
 * Some expub partners' backends already have a standalone check-uid
 * endpoint anyway (predates this SDK, or used by their own tooling) —
 * implement \Alogame\PaymentSdk\Expub\Contracts\CheckUidHookInterface
 * as well if yours does; ExpubHandler::handleCheckUid() answers 404
 * NOT_CONFIGURED for any hooks object that doesn't. (Moved out of this
 * required interface in 2.0.0 — see CHANGELOG.)
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

    /** Reserve the top-up server-side; return the order reference to remember for onPaymentReceived. */
    public function onCreateOrder(CreateOrderRequest $request): CreateOrderResult;

    /** Payment confirmed by Alogame — deliver the item now. */
    public function onPaymentReceived(PaymentReceivedRequest $request): PaymentReceivedResult;
}
