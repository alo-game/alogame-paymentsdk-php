<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Contracts;

use Alogame\PaymentSdk\WebPay\Dto\CheckUidRequest;
use Alogame\PaymentSdk\WebPay\Dto\CheckUidResult;
use Alogame\PaymentSdk\WebPay\Dto\CreateOrderRequest;
use Alogame\PaymentSdk\WebPay\Dto\CreateOrderResult;
use Alogame\PaymentSdk\WebPay\Dto\PaymentReceivedRequest;
use Alogame\PaymentSdk\WebPay\Dto\PaymentReceivedResult;

/**
 * The only thing a game backend has to implement. All three calls are
 * ALWAYS initiated by Alogame API — a player's top-up starts on the
 * Alogame Portal, never on this side. Implementations should treat every
 * call as idempotent: Alogame retries createOrder/paymentReceived on
 * timeout, so the same order may arrive more than once.
 */
interface WebpayHookInterface
{
    /** Does this uid exist on this game? */
    public function onCheckUid(CheckUidRequest $request): CheckUidResult;

    /** Reserve the top-up server-side; return the order reference to remember for paymentReceived. */
    public function onCreateOrder(CreateOrderRequest $request): CreateOrderResult;

    /** Payment confirmed by Alogame — deliver the item now. */
    public function onPaymentReceived(PaymentReceivedRequest $request): PaymentReceivedResult;
}
