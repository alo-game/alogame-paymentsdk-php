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
use Alogame\PaymentSdk\WebPay\Signature\HmacSignatureVerifier;

/**
 * The one class an expub game backend wires into its own router — three
 * routes, three calls to the three methods below, three hook
 * implementations. Everything else (signature, timestamp freshness, JSON
 * parsing, the exact response shape/HTTP status per outcome) lives here,
 * the same reasoning as WebpayHandler: a hand-written endpoint has no way
 * to know it got a wire field name or a status code wrong until a real
 * player's order fails.
 *
 * This implements the expub contract — used by exclusive/direct-publishing
 * (expub) games; co-publishing games use \Alogame\PaymentSdk\WebPay\
 * WebpayHandler instead. It answers whichever signature scheme the game
 * picked in Console, detected per request from the headers Alogame sent —
 * nothing to configure here, and nothing that can drift from Console:
 *
 *  - MD5 (every expub game integrated before HMAC): `Signature` header,
 *    `timestamp` (seconds) as a body field, errors via HTTP status.
 *  - HMAC-SHA256 (recommended for a new integration): `x-timestamp`
 *    (milliseconds) + `x-signature` headers, exactly as WebpayHandler
 *    verifies them, and every business outcome answered 200 with an
 *    `{errcode, msg, data}` envelope — the only shape Alogame's HMAC
 *    strategy parses (it treats any non-2xx as a transport failure). A
 *    body `timestamp` is never sent under this scheme, which is why the MD5
 *    verifier can't be used for it: every call would fail as expired.
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
 * hook implementer's benefit. createOrder/paymentReceived also accept the
 * WebPay spellings (plat_order_num, productid, amount, server_id,
 * order_num): under HMAC, web top-up (api-game) sends the expub names but
 * Mobile IAP (backend-api) sends the WebPay ones, to the SAME two URLs.
 */
final class ExpubHandler
{
    /**
     * Bumped on any change to the wire contract this class implements (not
     * on internal refactors).
     */
    public const VERSION = '1.1.0';

    private readonly Md5SignatureVerifier $md5Verifier;

    private readonly HmacSignatureVerifier $hmacVerifier;

    /**
     * Set per request by dispatch() — which scheme the call in flight was
     * signed with, and therefore which response shape it expects back.
     */
    private bool $hmac = false;

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
        $this->md5Verifier = new Md5SignatureVerifier($secret, $timestampToleranceSeconds);
        $this->hmacVerifier = new HmacSignatureVerifier($secret, $timestampToleranceSeconds);
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

            $characters = array_map(
                static fn (UserCharacter $c): array => array_filter([
                    'uid' => $c->uid,
                    'characterName' => $c->characterName,
                    'server' => $c->server,
                ], static fn (mixed $v): bool => $v !== null),
                $this->hooks->onGetUserList($request),
            );

            if ($this->hmac) {
                return self::envelope(0, 'success', $characters);
            }

            return new Response(200, [
                'userId' => $request->userId,
                'uids' => $characters,
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
                return $this->hmac
                    ? self::envelope(1, 'uid not found')
                    : new Response(404, [
                        'error' => ['code' => 'UID_NOT_FOUND', 'message' => 'uid does not exist.'],
                    ]);
            }

            $character = array_filter([
                'characterName' => $result->characterName,
                'server' => $result->server,
            ], static fn (mixed $v): bool => $v !== null);

            return $this->hmac
                ? self::envelope(0, 'success', $character)
                : new Response(200, $character);
        });
    }

    /**
     * @param array<string, string> $headers
     */
    public function handleCreateOrder(array $headers, string $rawBody): Response
    {
        return $this->dispatch($headers, $rawBody, function (array $payload): Response {
            $request = new CreateOrderRequest(
                orderId: self::requireString($payload, 'order_id', 'plat_order_num'),
                uid: self::requireString($payload, 'uid'),
                productId: self::requireString($payload, 'productId', 'productid'),
                amount: self::optionalInt($payload, 'price', 'amount'),
                serverId: self::optionalString($payload, 'serverId', 'server_id'),
                osId: self::optionalString($payload, 'os_id'),
                extInfo: self::optionalString($payload, 'ext_info'),
            );

            $result = $this->hooks->onCreateOrder($request);

            if ($this->hmac) {
                // A duplicate is a success on this scheme: Alogame retried
                // an order it already created, and needs the ORIGINAL
                // order_num back — the same answer as the first call.
                return match (true) {
                    $result->productNotFound => self::envelope(1, 'product not found'),
                    $result->uidNotFound => self::envelope(1, 'uid not found'),
                    default => self::envelope(0, 'success', ['order_num' => $result->orderCode]),
                };
            }

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
                orderCode: self::requireString($payload, 'order_code', 'order_num'),
                orderId: self::optionalString($payload, 'order_id', 'plat_order_num'),
                amount: self::optionalInt($payload, 'price', 'amount'),
                extInfo: self::optionalString($payload, 'ext_info'),
            );

            $result = $this->hooks->onPaymentReceived($request);

            if ($this->hmac) {
                // Already delivered is errcode 0 too: anything else makes
                // Alogame retry a delivery that already happened.
                return $result->orderCodeNotFound
                    ? self::envelope(1, 'order_code not found')
                    : self::envelope(0, 'success');
            }

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
        // Alogame's HMAC strategy always sends x-signature and its MD5 ones
        // never do, so the header alone says which scheme — and which
        // response shape — this call uses. Both are keyed by the same
        // secret, so the choice gives a caller without it nothing.
        // HMAC signs the raw bytes, so it's checked before parsing; MD5
        // signs the decoded fields (plus the body `timestamp`), so after.
        $this->hmac = self::header($headers, 'x-signature') !== null;
        if ($this->hmac) {
            try {
                $this->hmacVerifier->verify(
                    self::header($headers, 'x-timestamp'),
                    self::header($headers, 'x-signature'),
                    $rawBody,
                );
            } catch (InvalidSignatureException $e) {
                return new Response(401, [
                    'error' => ['code' => 'SIGNATURE_INVALID', 'message' => $e->getMessage()],
                ]);
            }
        }

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

        if (!$this->hmac) {
            try {
                $this->md5Verifier->verify($payload, self::header($headers, 'Signature'));
            } catch (InvalidSignatureException $e) {
                return new Response(401, [
                    'error' => ['code' => 'SIGNATURE_INVALID', 'message' => $e->getMessage()],
                ]);
            }
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
     * The `{errcode, msg, data}` envelope every HMAC-scheme answer uses —
     * always HTTP 200, the outcome lives in `errcode`.
     *
     * @param array<mixed>|null $data
     */
    private static function envelope(int $errcode, string $msg, ?array $data = null): Response
    {
        $body = ['errcode' => $errcode, 'msg' => $msg];
        if ($data !== null) {
            $body['data'] = $data;
        }

        return new Response(200, $body);
    }

    /**
     * First of $keys present in $payload — the expub spelling first, then
     * its WebPay alias (see the class docblock for why both arrive).
     *
     * @param array<string, mixed> $payload
     */
    private static function pick(array $payload, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function requireString(array $payload, string $key, string ...$aliases): string
    {
        $value = self::pick($payload, $key, ...$aliases);
        if (!is_string($value) || $value === '') {
            throw new InvalidPayloadException("Parameter [$key] is missing.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function optionalString(array $payload, string $key, string ...$aliases): ?string
    {
        $value = self::pick($payload, $key, ...$aliases);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function optionalInt(array $payload, string $key, string ...$aliases): ?int
    {
        $value = self::pick($payload, $key, ...$aliases);
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
