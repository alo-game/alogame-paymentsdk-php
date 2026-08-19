<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Expub\Dto;

/**
 * $userId is Alogame's own account id, not anything this game issued —
 * return every character this account has ever linked, so the player can
 * pick one on the Alogame side. $extInfo is opaque context Alogame forwards
 * unchanged; most integrations never read it.
 */
final class GetUserListRequest
{
    public function __construct(
        public readonly string $userId,
        public readonly ?string $extInfo = null,
    ) {
    }
}
