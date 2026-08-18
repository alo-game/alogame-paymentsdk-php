<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Exceptions;

/**
 * Thrown by HmacSignatureVerifier when x-signature doesn't match, or x-timestamp
 * is missing/malformed/outside the tolerance window. The partner's own hook
 * code never sees this — WebpayHandler catches it and answers Alogame API
 * with the SYSTEM_ERROR envelope before any hook runs.
 */
final class InvalidSignatureException extends \RuntimeException
{
}
