<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Expub\Signature;

use Alogame\PaymentSdk\Exceptions\InvalidSignatureException;

/**
 * Matches the `md5_timestamp` strategy every expub/legacy game provider
 * uses on Alogame's side (backend-api's signatures/md5Timestamp.js):
 *
 * 1. Take every request-body field EXCEPT `timestamp` itself.
 * 2. Sort those keys, join as `k1=v1&k2=v2&...`.
 * 3. signatureString = queryString + secret + timestamp
 * 4. signature = md5hex(signatureString)
 *
 * Unlike WebPay's HMAC (which hashes raw, unparsed bytes), this scheme signs
 * the DECODED field set — because which fields are present varies per
 * endpoint and per game config (backend-api only sends the fields that
 * game's `game_api.endpoints.*.params` lists), there is no fixed "raw body"
 * shape to hash instead. `timestamp` itself is a plain seconds-since-epoch
 * integer in the body, not a header — this is the older of the two contracts
 * this SDK implements, predating x-timestamp/x-signature headers.
 */
final class Md5SignatureVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 600,
    ) {
    }

    /**
     * @param array<string, mixed> $payload the full decoded JSON request
     *        body, including `timestamp` — every other key is treated as a
     *        signed field, whatever the endpoint.
     *
     * @throws InvalidSignatureException when `timestamp` is missing/malformed/
     *         outside the tolerance window, or the signature doesn't match.
     */
    public function verify(array $payload, ?string $signatureHeader): void
    {
        if ($signatureHeader === null || $signatureHeader === '') {
            throw new InvalidSignatureException('Signature header is missing.');
        }

        $timestampValue = $payload['timestamp'] ?? null;
        $isIntegerish = is_int($timestampValue)
            || (is_string($timestampValue) && ctype_digit($timestampValue));
        if (!$isIntegerish) {
            throw new InvalidSignatureException('timestamp field is missing or not an epoch-second integer.');
        }

        $timestamp = (int) $timestampValue;
        if (abs(time() - $timestamp) > $this->toleranceSeconds) {
            throw new InvalidSignatureException('timestamp is outside the allowed window — request may be replayed or clocks are out of sync.');
        }

        $params = $payload;
        unset($params['timestamp']);
        ksort($params, SORT_STRING);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . self::stringify($value);
        }
        $queryString = implode('&', $pairs);

        $expected = md5($queryString . $this->secret . $timestamp);
        if (!hash_equals($expected, strtolower($signatureHeader))) {
            throw new InvalidSignatureException('Signature does not match the request body.');
        }
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_scalar($value)) {
            throw new InvalidSignatureException('A signed field is not a scalar value.');
        }

        return (string) $value;
    }
}
