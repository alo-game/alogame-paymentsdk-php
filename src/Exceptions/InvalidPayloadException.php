<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Exceptions;

/**
 * Thrown when the request body isn't valid JSON, or is missing a field the
 * relevant DTO requires. Distinct from InvalidSignatureException so a caller
 * inspecting a caught exception can tell "not from Alogame" apart from
 * "from Alogame, but malformed" — the two map to different HTTP statuses.
 */
final class InvalidPayloadException extends \RuntimeException
{
}
