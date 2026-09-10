<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Contracts;

use Alogame\PaymentSdk\WebPay\Dto\CharacterInfo;
use Alogame\PaymentSdk\WebPay\Dto\GetCharacterListRequest;

/**
 * Separate from WebpayHookInterface for the same reason
 * ServerListHookInterface is: in most games a uid identifies exactly one
 * character, and those games shouldn't have to implement a method that can
 * only ever return one element. Implement this ONLY if one uid of yours can
 * own several characters and the player must pick which one receives the
 * top-up — Alogame derives "does this game need a character picker" from
 * whether your hooks object implements this interface.
 *
 * Orthogonal to ServerListHookInterface: implement either, both, or
 * neither. Both means the player picks a server, types a uid, then picks a
 * character.
 */
interface CharacterListHookInterface
{
    /**
     * Called only after onCheckUid has already accepted $request->uid, so
     * there is no not-found case to signal here: an empty array simply
     * means "this uid owns no character", which Alogame shows as an empty
     * picker rather than an error.
     *
     * @return CharacterInfo[]
     */
    public function onGetCharacterList(GetCharacterListRequest $request): array;
}
