<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Contracts;

use Alogame\PaymentSdk\WebPay\Dto\GetUserListRequest;
use Alogame\PaymentSdk\WebPay\Dto\UserCharacter;

/**
 * The HMAC counterpart of Expub's onGetUserList, for a game whose players
 * log into their Alogame account and have characters linked to it ahead of
 * time — Alogame then shows those characters instead of asking the player
 * to type a uid. Separate from WebpayHookInterface for the same reason
 * ServerListHookInterface is: a co-pub game (uid typed by the player, no
 * Alogame login) has no account to look characters up by, and shouldn't
 * have to implement a method it can never answer.
 *
 * Without this, an HMAC game had no way to serve get_user_list through the
 * SDK at all: ExpubHandler is the only other handler with that route, and
 * it verifies MD5 with a body `timestamp` — a request Alogame never sends
 * to a game configured for HMAC.
 */
interface UserListHookInterface
{
    /**
     * An empty array means "this account has no linked character", which
     * Alogame shows as an empty list rather than an error — including for
     * a $request->userId this game has never seen.
     *
     * @return UserCharacter[]
     */
    public function onGetUserList(GetUserListRequest $request): array;
}
