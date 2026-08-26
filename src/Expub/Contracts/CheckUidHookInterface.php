<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Expub\Contracts;

use Alogame\PaymentSdk\Expub\Dto\CheckUidRequest;
use Alogame\PaymentSdk\Expub\Dto\CheckUidResult;

/**
 * Separate from ExpubHookInterface on purpose: an expub player always
 * reaches checkout by logging into Alogame and picking a character from
 * onGetUserList's own response, so uid existence is already proven by
 * construction — Alogame's own checkout flow never calls check-uid for an
 * expub game. Implement this ONLY if your backend already has (or wants) a
 * standalone check-uid endpoint for other reasons — e.g. it existed before
 * this SDK did, or your own Console-side test tooling calls it during
 * onboarding. Alogame derives "does this game expose a check-uid endpoint"
 * from whether your hooks object implements this interface, not from a
 * config flag. Games with no reason to have one can simply not implement
 * it — ExpubHandler::handleCheckUid() answers 404 NOT_CONFIGURED itself.
 */
interface CheckUidHookInterface
{
    /** Does this character still exist? */
    public function onCheckUid(CheckUidRequest $request): CheckUidResult;
}
