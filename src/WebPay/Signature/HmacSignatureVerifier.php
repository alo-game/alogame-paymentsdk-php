<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay\Signature;

use Alogame\PaymentSdk\Exceptions\InvalidSignatureException;

/**
 * Matches Alogame API's `hmac_sha256_timestamp_body` strategy exactly
 * (api-game's genericGameAdapter.js): signed string is the literal
 * `x-timestamp` header value (epoch milliseconds, as the exact digits
 * Alogame sent — never re-formatted) concatenated with the raw request
 * body bytes, HMAC-SHA256'd with the shared secret, hex-encoded.
 *
 * Deliberately hashes $rawBody as received rather than re-encoding a
 * decoded array: JSON key order/whitespace would then depend on this
 * language's own json_encode, which is not guaranteed to reproduce the
 * exact bytes Alogame signed on its side.
 */
final class HmacSignatureVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {
    }

    /**
     * @throws InvalidSignatureException when the timestamp is missing,
     *         malformed, outside the tolerance window, or the signature
     *         doesn't match.
     */
    public function verify(?string $timestampHeader, ?string $signatureHeader, string $rawBody): void
    {
        if ($timestampHeader === null || $timestampHeader === '') {
            throw new InvalidSignatureException('x-timestamp header is missing.');
        }
        if (!ctype_digit($timestampHeader)) {
            throw new InvalidSignatureException('x-timestamp header must be an epoch-millisecond integer.');
        }
        if ($signatureHeader === null || $signatureHeader === '') {
            throw new InvalidSignatureException('x-signature header is missing.');
        }

        $timestampMs = (int) $timestampHeader;
        $nowMs = (int) round(microtime(true) * 1000);
        if (abs($nowMs - $timestampMs) > $this->toleranceSeconds * 1000) {
            throw new InvalidSignatureException('x-timestamp is outside the allowed window — request may be replayed or clocks are out of sync.');
        }

        $expected = hash_hmac('sha256', $timestampHeader . $rawBody, $this->secret);
        if (!hash_equals($expected, strtolower($signatureHeader))) {
            throw new InvalidSignatureException('x-signature does not match the request body.');
        }
    }
}
