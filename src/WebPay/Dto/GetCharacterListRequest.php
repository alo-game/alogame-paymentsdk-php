<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Dto;

/**
 * Unlike GetServerListRequest, this one carries the $uid Alogame has
 * ALREADY validated through onCheckUid — the character list is scoped to
 * that uid (and to $serverId, for a game that also has a server list),
 * never to an account. $uid is therefore always present, not optional.
 */
final class GetCharacterListRequest
{
    public function __construct(
        public readonly string $uid,
        public readonly ?string $serverId = null,
        public readonly ?string $gameId = null,
    ) {
    }
}
