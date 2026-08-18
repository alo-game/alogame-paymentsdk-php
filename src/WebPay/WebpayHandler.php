<?php

declare(strict_types=1);

namespace Alogame\PaymentSdk\WebPay;

use Alogame\PaymentSdk\WebPay\Contracts\ServerListHookInterface;
use Alogame\PaymentSdk\WebPay\Contracts\WebpayHookInterface;
use Alogame\PaymentSdk\WebPay\Dto\CheckUidRequest;
use Alogame\PaymentSdk\WebPay\Dto\CreateOrderRequest;
use Alogame\PaymentSdk\WebPay\Dto\GetServerListRequest;
use Alogame\PaymentSdk\WebPay\Dto\PaymentReceivedRequest;
use Alogame\PaymentSdk\WebPay\Dto\ServerInfo;
use Alogame\PaymentSdk\Exceptions\InvalidPayloadException;
use Alogame\PaymentSdk\Exceptions\InvalidSignatureException;
use Alogame\PaymentSdk\Http\Response;
use Alogame\PaymentSdk\WebPay\Signature\HmacSignatureVerifier;

/**
 * The one class a game backend wires into its own router — three routes,
 * three calls to the three methods below, three hook implementations.
 * Everything else (signature, timestamp freshness, JSON parsing, the
 * response envelope's exact shape) lives here so no two integrations can
 * drift into incompatible field names, which is the exact failure mode
 * that motivated this SDK (see og-029's `plat_order_num`/`productid`
 * mismatches — a hand-written endpoint has no way to know it got these
 * wrong until a real player's order fails).
 *
 * Wire field names below are Alogame API's own defaults
 * (genericGameAdapter.js) verbatim — never rename them here. Renaming
 * happens once, in the DTOs' property names, for the hook implementer's
 * benefit; the bytes on the wire must stay exactly what Alogame already
 * sends, or Console's `fieldNames` override (a per-partner escape hatch
 * for endpoints that predate this SDK) becomes necessary again.
 */
final class WebpayHandler
{
    /**
     * Bumped on any change to the wire contract this class implements (not
     * on internal refactors). Returned by handleHealthCheck() so Alogame
     * support can tell which contract version a partner is actually
     * running without asking them to check composer.lock.
     */
    public const VERSION = '1.0.0';

    private readonly HmacSignatureVerifier $verifier;

    /**
     * @param (\Closure(\Throwable): void)|null $onError Called with any
     *        exception a hook throws that isn't InvalidPayloadException —
     *        wire this to your own logger/error tracker. The player-facing
     *        response is always the generic INTERNAL_ERROR envelope below
     *        regardless of whether this is set; it exists for visibility,
     *        not to change the response. A plain callable (e.g. `[$logger,
     *        'error']`) works too — wrap it with `Closure::fromCallable()`
     *        before passing it in, since PHP doesn't allow a `callable`-typed
     *        property.
     */
    public function __construct(
        string $secret,
        private readonly WebpayHookInterface $hooks,
        int $timestampToleranceSeconds = 300,
        private readonly ?\Closure $onError = null,
    ) {
        $this->verifier = new HmacSignatureVerifier($secret, $timestampToleranceSeconds);
    }

    /**
     * @param array<string, string> $headers
     */
    public function handleCheckUid(array $headers, string $rawBody): Response
    {
        return $this->dispatch($headers, $rawBody, function (array $payload): Response {
            $request = new CheckUidRequest(
                uid: self::requireString($payload, 'uid'),
                serverId: self::optionalString($payload, 'server_id'),
                gameId: self::optionalString($payload, 'game_id'),
            );

            $result = $this->hooks->onCheckUid($request);

            if (!$result->found) {
                return new Response(200, [
                    'errcode' => 1,
                    'msg' => 'user not found',
                ]);
            }

            return new Response(200, [
                'errcode' => 0,
                'msg' => 'success',
                'data' => [
                    'nickname' => $result->nickname,
                    'server' => $result->server,
                ],
            ]);
        });
    }

    /**
     * @param array<string, string> $headers
     */
    public function handleCreateOrder(array $headers, string $rawBody): Response
    {
        return $this->dispatch($headers, $rawBody, function (array $payload): Response {
            $request = new CreateOrderRequest(
                orderId: self::requireString($payload, 'plat_order_num'),
                uid: self::requireString($payload, 'uid'),
                productId: self::requireString($payload, 'productid'),
                amount: self::requireInt($payload, 'amount'),
                sandbox: (bool) ($payload['sandbox'] ?? false),
                serverId: self::optionalString($payload, 'server_id'),
                gameId: self::optionalString($payload, 'game_id'),
            );

            $result = $this->hooks->onCreateOrder($request);

            return new Response(200, [
                'errcode' => 0,
                'msg' => 'success',
                'data' => [
                    'order_num' => $result->orderCode,
                ],
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
                orderId: self::requireString($payload, 'plat_order_num'),
                orderCode: self::requireString($payload, 'order_num'),
                amount: self::requireInt($payload, 'amount'),
                gameId: self::optionalString($payload, 'game_id'),
            );

            $result = $this->hooks->onPaymentReceived($request);

            return new Response(200, [
                'errcode' => $result->ok ? 0 : 1,
            ]);
        });
    }

    /**
     * Only meaningful for a game that implements ServerListHookInterface —
     * every other game answers 404 here, which is the correct response:
     * Alogame never calls this unless Console's config says this game has
     * a server list in the first place.
     *
     * @param array<string, string> $headers
     */
    public function handleGetServerList(array $headers, string $rawBody): Response
    {
        return $this->dispatch($headers, $rawBody, function (array $payload): Response {
            if (!$this->hooks instanceof ServerListHookInterface) {
                return new Response(404, [
                    'error' => ['code' => 'NOT_CONFIGURED', 'message' => 'This game has no server list.'],
                ]);
            }

            $request = new GetServerListRequest(
                gameId: self::optionalString($payload, 'game_id'),
            );

            $servers = $this->hooks->onGetServerList($request);

            return new Response(200, [
                'errcode' => 0,
                'data' => array_map(
                    static fn (ServerInfo $server): array => ['serverId' => $server->serverId, 'name' => $server->name],
                    $servers,
                ),
            ]);
        });
    }

    /**
     * A fourth route with no business hook behind it — wire it once and
     * never touch it again. Alogame calls this (signed, same as every
     * other call) to confirm two things at once: your endpoint is
     * reachable, AND the secret Console has on file for you still matches
     * what your backend is checking against. A ping that isn't signed
     * would only prove the first half.
     *
     * @param array<string, string> $headers
     */
    public function handleHealthCheck(array $headers, string $rawBody = '{}'): Response
    {
        try {
            $this->verifier->verify(
                self::header($headers, 'x-timestamp'),
                self::header($headers, 'x-signature'),
                $rawBody,
            );
        } catch (InvalidSignatureException $e) {
            return new Response(401, [
                'error' => ['code' => 'SIGNATURE_INVALID', 'message' => $e->getMessage()],
            ]);
        }

        return new Response(200, [
            'errcode' => 0,
            'msg' => 'ok',
            'sdk_version' => self::VERSION,
        ]);
    }

    /**
     * @param array<string, string> $headers
     * @param callable(array<string, mixed>): Response $action
     */
    private function dispatch(array $headers, string $rawBody, callable $action): Response
    {
        try {
            $this->verifier->verify(
                self::header($headers, 'x-timestamp'),
                self::header($headers, 'x-signature'),
                $rawBody,
            );
        } catch (InvalidSignatureException $e) {
            return new Response(401, [
                'error' => ['code' => 'SIGNATURE_INVALID', 'message' => $e->getMessage()],
            ]);
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

        try {
            return $action($payload);
        } catch (InvalidPayloadException $e) {
            return new Response(400, [
                'error' => ['code' => 'SYSTEM_ERROR', 'message' => $e->getMessage()],
            ]);
        } catch (\Throwable $e) {
            // A hook threw something we didn't ask for — a DB error, a bug,
            // anything. Never let that escape as a raw exception: a
            // partner's own framework might turn it into an HTML error page
            // or a stack trace, neither of which api-game's response parser
            // can do anything with. $onError is the dev's only chance to
            // find out this happened at all.
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
    private static function requireInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        $isIntegerish = is_int($value)
            || (is_float($value) && floor($value) === $value)
            || (is_string($value) && ctype_digit($value));

        if (!$isIntegerish) {
            throw new InvalidPayloadException("Parameter [$key] is missing.");
        }

        return (int) $value;
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
