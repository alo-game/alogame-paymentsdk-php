<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Expub\Dto;

/**
 * One character belonging to the Alogame user asked about in
 * GetUserListRequest — an expub game returns every character linked to that
 * account, not just one, since the player picks which one to top up on
 * Alogame's side after seeing this list.
 */
final class UserCharacter
{
    public function __construct(
        public readonly string $uid,
        public readonly string $characterName,
        public readonly ?string $server = null,
    ) {
    }
}
