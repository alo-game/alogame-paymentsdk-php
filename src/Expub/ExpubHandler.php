<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\Expub;

use Alogame\PaymentSdk\Expub\Contracts\CheckUidHookInterface;
use Alogame\PaymentSdk\Expub\Contracts\ExpubHookInterface;
use Alogame\PaymentSdk\Expub\Dto\CheckUidRequest;
use Alogame\PaymentSdk\Expub\Dto\CreateOrderRequest;
use Alogame\PaymentSdk\Expub\Dto\GetUserListRequest;
use Alogame\PaymentSdk\Expub\Dto\PaymentReceivedRequest;
use Alogame\PaymentSdk\Expub\Dto\UserCharacter;
use Alogame\PaymentSdk\Exceptions\InvalidPayloadException;
use Alogame\PaymentSdk\Exceptions\InvalidSignatureException;
use Alogame\PaymentSdk\Http\Response;
use Alogame\PaymentSdk\Expub\Signature\Md5SignatureVerifier;

/**
 * The one class an expub game backend wires into its own router — three
 * routes, three calls to the three methods below, three hook
 * implementations. Everything else (signature, timestamp freshness, JSON
 * parsing, the exact response shape/HTTP status per outcome) lives here,
 * the same reasoning as WebpayHandler: a hand-written endpoint has no way
 * to know it got a wire field name or a status code wrong until a real
 * player's order fails.
 *
 * This implements the OLDER of the two contracts this SDK ships — MD5,
 * `Signature` header, errors via HTTP status — used by exclusive/direct-
 * publishing (expub) games. New co-publishing games should use \Alogame\
 * PaymentSdk\WebPay\WebpayHandler instead; the two are not interchangeable.
 *
 * onCreateOrder/onPaymentReceived also serve this same game's Mobile IAP
 * purchases — `createOrder_url`/`exchange_url` are shared, distinguished
 * only by CreateOrderRequest::$osId.
 *
 * `handleCheckUid` answers 404 NOT_CONFIGURED unless $hooks additionally
 * implements the OPTIONAL CheckUidHookInterface — see that interface's own
 * docblock for why this isn't one of the three required hooks. (Moved out
 * of the required interface in 2.0.0; see CHANGELOG.)
 *
 * Wire field names below are Alogame API's own defaults
 * (backend-api's GenericSignatureGameAdapter.js) verbatim — never rename
 * them here; renaming happens once, in the DTOs' property names, for the
 * hook implementer's benefit.
 */
final class ExpubHandler
{
    /**
     * Bumped on any change to the wire contract this class implements (not
     * on internal refactors).
     */
    public const VERSION = '1.0.0';

    private readonly Md5SignatureVerifier $verifier;

    /**
     * @param (\Closure(\Throwable): void)|null $onError Called with any
     *        exception a hook throws that isn't InvalidPayloadException —
     *        wire this to your own logger/error tracker. The player-facing
     *        response is always the generic INTERNAL_ERROR envelope below
     *        regardless of whether this is set; it exists for visibility,
     *        not to change the response.
     */
    public function __construct(
        string $secret,
        private readonly ExpubHookInterface $hooks,
        int $timestampToleranceSeconds = 600,
        private readonly ?\Closure $onError = null,
    ) {
        $this->verifier = new Md5SignatureVerifier($secret, $timestampToleranceSeconds);
    }

    /**
     * @param array<string, string> $headers
     */
    public function handleGetUserList(array $headers, string $rawBody): Response
    {
        return $this->dispatch($headers, $rawBody, function (array $payload): Response {
            $request = new GetUserListRequest(
                userId: self::requireString($payload, 'userId'),
                extInfo: self::optionalString($payload, 'ext_info'),
            );

            $characters = $this->hooks->onGetUserList($request);

            return new Response(200, [
                'userId' => $request->userId,
                'uids' => array_map(
                    static fn (UserCharacter $c): array => array_filter([
                        'uid' => $c->uid,
                        'characterName' => $c->characterName,
                        'server' => $c->server,
                    ], static fn (mixed $v): bool => $v !== null),
                    $characters,
                ),
            ]);
        });
    }

    /**
     * Only meaningful for a game that implements CheckUidHookInterface —
     * every other game answers 404 here, which is the correct response:
     * Alogame never calls this for an expub game's own checkout flow, and
     * this endpoint exists only for partners whose backend already has one
     * for other reasons.
     *
     * @param array<string, string> $headers
     */
    public function handleCheckUid(array $headers, string $rawBody): Response
    {
        return $this->dispatch($headers, $rawBody, function (array $payload): Response {
            if (!$this->hooks instanceof CheckUidHookInterface) {
                return new Response(404, [
                    'error' => ['code' => 'NOT_CONFIGURED', 'message' => 'This game has no check-uid endpoint.'],
                ]);
            }

            $request = new CheckUidRequest(
                uid: self::requireString($payload, 'uid'),
                extInfo: self::optionalString($payload, 'ext_info'),
            );

            $result = $this->hooks->onCheckUid($request);

            if (!$result->found) {
                return new Response(404, [
                    'error' => ['code' => 'UID_NOT_FOUND', 'message' => 'uid does not exist.'],
                ]);
            }

            return new Response(200, array_filter([
                'characterName' => $result->characterName,
                'server' => $result->server,
            ], static fn (mixed $v): bool => $v !== null));
        });
    }

    /**
     * @param array<string, string> $headers
     */
    public function handleCreateOrder(array $headers, string $rawBody): Response
    {
        return $this->dispatch($headers, $rawBody, function (array $payload): Response {
            $request = new CreateOrderRequest(
                orderId: self::requireString($payload, 'order_id'),
                uid: self::requireString($payload, 'uid'),
                productId: self::requireString($payload, 'productId'),
                amount: self::optionalInt($payload, 'price'),
                serverId: self::optionalString($payload, 'serverId'),
                osId: self::optionalString($payload, 'os_id'),
                extInfo: self::optionalString($payload, 'ext_info'),
            );

            $result = $this->hooks->onCreateOrder($request);

            if ($result->productNotFound) {
                return new Response(404, [
                    'error' => ['code' => 'PRODUCT_NOT_FOUND', 'message' => 'productId does not exist.'],
                ]);
            }

            if ($result->uidNotFound) {
                return new Response(404, [
                    'error' => ['code' => 'UID_NOT_FOUND', 'message' => 'uid does not exist.'],
                ]);
            }

            if ($result->duplicate) {
                return new Response(409, [
                    'error' => ['code' => 'ORDER_ALREADY_EXISTS', 'message' => 'order_id was already used for an existing order.'],
                    'order_code' => $result->orderCode,
                ]);
            }

            return new Response(201, [
                'status' => 'success',
                'order_code' => $result->orderCode,
            ]);
        });
    }

    /**
     * @param array<string, string> $headers
     */
    public function handlePaymentReceived(array $headers, string $rawBody): Response
    {
        return $this->dispatch($headers, $rawBody, function (array $payload): Response {
            $request = new PaymentReceivedRequest(
                orderCode: self::requireString($payload, 'order_code'),
                orderId: self::optionalString($payload, 'order_id'),
                amount: self::optionalInt($payload, 'price'),
                extInfo: self::optionalString($payload, 'ext_info'),
            );

            $result = $this->hooks->onPaymentReceived($request);

            if ($result->orderCodeNotFound) {
                return new Response(404, [
                    'error' => ['code' => 'ORDER_CODE_NOT_FOUND', 'message' => 'order_code does not exist.'],
                ]);
            }

            if ($result->alreadyProcessed) {
                return new Response(409, [
                    'error' => ['code' => 'PAYMENT_ALREADY_PROCESSED', 'message' => 'This order was already delivered.'],
                ]);
            }

            return new Response(200, [
                'processingStatus' => 'completed',
            ]);
        });
    }

    /**
     * @param array<string, string> $headers
     * @param callable(array<string, mixed>): Response $action
     */
    private function dispatch(array $headers, string $rawBody, callable $action): Response
    {
        try {
            $payload = json_decode($rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new Response(400, [
                'error' => ['code' => 'INVALID_JSON', 'message' => 'Request body is not valid JSON.'],
            ]);
        }

        if (!is_array($payload)) {
            return new Response(400, [
                'error' => ['code' => 'INVALID_JSON', 'message' => 'Request body must be a JSON object.'],
            ]);
        }

        try {
            $this->verifier->verify($payload, self::header($headers, 'Signature'));
        } catch (InvalidSignatureException $e) {
            return new Response(401, [
                'error' => ['code' => 'SIGNATURE_INVALID', 'message' => $e->getMessage()],
            ]);
        }

        try {
            return $action($payload);
        } catch (InvalidPayloadException $e) {
            return new Response(400, [
                'error' => ['code' => 'SYSTEM_ERROR', 'message' => $e->getMessage()],
            ]);
        } catch (\Throwable $e) {
            // A hook threw something we didn't ask for — a DB error, a bug,
            // anything. Never let that escape as a raw exception. $onError
            // is the dev's only chance to find out this happened at all.
            if ($this->onError !== null) {
                ($this->onError)($e);
            }

            return new Response(500, [
                'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Internal error.'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requireString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidPayloadException("Parameter [$key] is missing.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function optionalInt(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        $isIntegerish = is_int($value)
            || (is_float($value) && floor($value) === $value)
            || (is_string($value) && ctype_digit($value));

        if (!$isIntegerish) {
            throw new InvalidPayloadException("Parameter [$key] must be an integer.");
        }

        return (int) $value;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        // Case-insensitive: getallheaders() preserves wire casing, PSR-7
        // implementations don't all agree on one either.
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
