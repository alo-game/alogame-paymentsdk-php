# Alogame Payment SDK (PHP)

Server-side SDK for a game backend integrating with Alogame's payment
flows. Ships the **WebPay** contract today (`Alogame\PaymentSdk\WebPay`) —
Iap is planned as a second module in this same package, see
[Roadmap](#roadmap). It handles signature verification, timestamp
freshness, JSON parsing, and the exact response envelope Alogame API
expects — a game backend only implements a handful of business hooks and
never touches raw HTTP, signing, or field names.

## Why this exists

Every WebPay integration on Alogame's side is driven by
[api-game](https://gitlab.oeg.vn/alogame-tech/microservices/api-game-alogame.vn)'s
generic adapter, which calls out to a game's own backend for three things —
`checkUid`, `createOrder`, `paymentReceived` — always initiated by Alogame,
never by the game. **A player's top-up always starts on the Alogame Portal,
not on the game's server** — a game backend only ever *reacts*, it never
calls anything on Alogame.

Without this SDK, a game backend hand-writes an HTTP endpoint against
Alogame's contract from a doc — and the exact field names / signing scheme
are easy to get subtly wrong in ways that only surface once a real player's
order fails (see `og029-webpay-standard-migration.md` for a real example).
This SDK makes that class of bug structurally impossible: the wire contract
lives in code, not in a doc a human re-implements by hand.

## Setup steps (what you, the game dev, actually do)

1. **Get your credentials from Alogame** — `game_code` and a shared HMAC
   `secret_key`, plus which environment (dev/staging vs prod) will call you
   first. You don't generate these; Alogame issues them.
2. **Install the SDK** — `composer require alo-game/paymentsdk`.
3. **Implement `WebpayHookInterface`** — write `onCheckUid`,
   `onCreateOrder`, `onPaymentReceived` against your own player/order data.
   See [Usage](#usage). This is the only business logic you write.
4. **Expose three routes** on your own backend, each calling the matching
   `WebpayHandler::handle*()` method. See the wiring example below — same
   three calls regardless of framework.
5. **Make the URL publicly reachable** by Alogame (not `localhost`, not an
   internal-only address), then send Alogame Ops the base URL + the three
   paths. This is the only thing you hand back — you never configure
   anything on Alogame's side yourself.
6. **Alogame Ops wires it into Console** (WebPay Config → Endpoints:
   checkUid/createOrder/paymentReceived paths, strategy = HMAC-SHA256,
   your secret from step 1). Nothing for you to do here — just confirm the
   values back if asked.
7. **Alogame tests against your endpoint** before going live, using
   Console's "Test API — check UID" tool with a real or sandbox UID you
   provide. Expect a few round-trips fixing anything that doesn't match —
   this is normal and exactly what step 7 is for.
8. **Go live** — Alogame flips "Active Webpay Config" on. From this moment
   every call is real player traffic. Make sure `onCreateOrder` and
   `onPaymentReceived` are safe to receive twice (Alogame retries
   automatically on a timeout) before this step.

## Install

```bash
composer require alo-game/paymentsdk
```

No auth needed — this package is published on
[Packagist](https://packagist.org/packages/alo-game/paymentsdk) via a
public mirror at `github.com/alo-game/alogame-paymentsdk-php`. **This
GitLab repository stays the private source of truth** (development, CI,
merge requests); the GitHub mirror only ever receives already-tested
tagged releases — see [Publishing a release](#publishing-a-release) if
you're on the Alogame side cutting one.

## The flow

```
Player                Alogame Portal        Alogame API           Your backend (this SDK)
  │  1. pick package        │                     │                        │
  ├─────────────────────────▶                     │                        │
  │                          │  2. checkUid        │                        │
  │                          ├─────────────────────▶  3. HTTP POST ─────────▶  WebpayHandler::handleCheckUid()
  │                          │                     │                        │      → your onCheckUid() hook
  │                          │                     │  ◀─────────────────────┤
  │                          │  4. createOrder     │                        │
  │                          ├─────────────────────▶  5. HTTP POST ─────────▶  WebpayHandler::handleCreateOrder()
  │                          │                     │                        │      → your onCreateOrder() hook
  │                          │                     │  ◀─────────────────────┤
  │  6. pays (napas/momo/…)  │                     │                        │
  ├──────────────────────────┼─────────────────────▶                        │
  │                          │                     │  7. HTTP POST ─────────▶  WebpayHandler::handlePaymentReceived()
  │                          │                     │                        │      → your onPaymentReceived() hook
  │                          │                     │  ◀─────────────────────┤    (you deliver the item here)
  │                          │  ◀───────────────── │                        │
  │  ◀───────────────────────┤  "Top-up successful"│                        │
```

Your backend never initiates any of this — it only implements the three
hooks and waits.

## Usage

Implement the one interface:

```php
use Alogame\PaymentSdk\WebPay\Contracts\WebpayHookInterface;
use Alogame\PaymentSdk\WebPay\Dto\{
    CheckUidRequest, CheckUidResult,
    CreateOrderRequest, CreateOrderResult,
    PaymentReceivedRequest, PaymentReceivedResult,
};

final class MyGameHooks implements WebpayHookInterface
{
    public function onCheckUid(CheckUidRequest $request): CheckUidResult
    {
        $player = MyPlayerRepository::findByUid($request->uid);

        return $player
            ? CheckUidResult::found($player->nickname, $player->serverName)
            : CheckUidResult::notFound();
    }

    public function onCreateOrder(CreateOrderRequest $request): CreateOrderResult
    {
        // $request->orderId is Alogame's own reference — store it so
        // onPaymentReceived can match this same order back to it.
        $order = MyOrderRepository::create(
            alogameOrderId: $request->orderId,
            uid: $request->uid,
            productId: $request->productId,
            amount: $request->amount,
        );

        return CreateOrderResult::created($order->id);
    }

    public function onPaymentReceived(PaymentReceivedRequest $request): PaymentReceivedResult
    {
        $order = MyOrderRepository::findByOwnId($request->orderCode);
        if ($order === null) {
            return PaymentReceivedResult::failed('unknown order');
        }

        MyInventory::deliver($order->uid, $order->productId);

        return PaymentReceivedResult::ok();
    }
}
```

Wire three routes to it (framework-agnostic — this example is plain PHP;
Laravel/Symfony just wrap the same three calls). Log anything a hook throws
via `onError`, so a bug in your own code doesn't disappear silently — the
player-facing response is always a clean generic error either way, never a
raw stack trace:

```php
use Alogame\PaymentSdk\WebPay\WebpayHandler;

$handler = new WebpayHandler(
    secret: getenv('ALOGAME_WEBPAY_SECRET'),
    hooks: new MyGameHooks(),
    onError: fn (\Throwable $e) => MyLogger::error($e),
);

// routes/webpay.php — wire these to whatever your framework/router calls
// "POST /webpay/check-uid", "/webpay/create-order", "/webpay/payment-received"
$response = $handler->handleCheckUid(getallheaders(), file_get_contents('php://input'));
http_response_code($response->status);
header('Content-Type: application/json');
echo json_encode($response->body);
```

## Optional: multiple servers

Only implement this if your game runs multiple servers and a player must
pick one before topping up. Alogame derives "does this game need a server
picker" from whether your hooks object implements this interface — nothing
to configure separately:

```php
use Alogame\PaymentSdk\WebPay\Contracts\ServerListHookInterface;
use Alogame\PaymentSdk\WebPay\Dto\{GetServerListRequest, ServerInfo};

final class MyGameHooks implements WebpayHookInterface, ServerListHookInterface
{
    // ...onCheckUid/onCreateOrder/onPaymentReceived as above...

    public function onGetServerList(GetServerListRequest $request): array
    {
        return array_map(
            static fn ($server) => new ServerInfo($server->id, $server->name),
            MyServerRepository::all(),
        );
    }
}
```

Wire a fourth route to `WebpayHandler::handleGetServerList()`. A game that
doesn't implement this interface answers 404 there automatically — you
don't need an `if` for it.

## Health check — "is my SDK actually listening?"

Wire a fifth route to `WebpayHandler::handleHealthCheck()`. Alogame calls
it the same way as every other request (signed, `x-timestamp`/`x-signature`
headers) — a 200 back proves two things at once: your endpoint is
reachable, *and* the secret Console has on file for you still matches what
your backend checks against. An unsigned ping would only ever prove the
first half, which is why this isn't a plain `GET /health`.

```php
$response = $handler->handleHealthCheck(getallheaders(), file_get_contents('php://input'));
```

Response on success: `{"errcode":0,"msg":"ok","sdk_version":"1.0.0"}` — the
version lets Alogame support tell which contract version you're running
without asking you to paste your `composer.lock`.

## Development

```bash
composer install
composer test    # phpunit
composer stan    # phpstan, level 8
```

## Publishing a release

GitLab (this repo) is the private source — GitHub is a release-only
mirror, same pattern and same script style as `alogame-kyc-sdk`'s
`scripts/deploy_ios_spm.sh`. It's a manual script run from your own
machine, not a CI job — nothing reaches the public mirror without someone
actually running it.

1. Bump `WebpayHandler::VERSION`, add a matching entry to `CHANGELOG.md`.
2. Merge to `main` via MR (CI's `test` job — phpunit + phpstan — must pass).
3. `./scripts/deploy_github.sh` — re-runs tests/phpstan locally, then
   copies the released file set, commits, tags, and pushes to
   `github.com/alo-game/alogame-paymentsdk-php`. Refuses to run if the
   constant, the CHANGELOG entry, and the tag you're about to push don't
   all agree.
4. First release only: submit the GitHub repo on
   [packagist.org](https://packagist.org/packages/submit) — it auto-syncs
   on every future push via GitHub's webhook, no manual step after that.

Needs `scripts/.env` (gitignored, never committed) with
`ALOGAME_RELEASE_GITHUB_TOKEN=github_pat_xxx` — a fine-grained GitHub PAT,
resource owner `alo-game`, Contents + Administration read/write. The same
token already used for `alogame-kyc-sdk` works here too, as long as its
repo access list also covers `alogame-paymentsdk-php` (or is org-wide).

## Roadmap

**Iap module — not started.** Mobile IAP (`createOrder_url`/`exchange_url`)
currently lives entirely in `backend-api` (`GenericSignatureGameAdapter` +
`signatures/md5Timestamp.js`), completely separate from this SDK's
`WebPay` module — different signing (MD5, not HMAC), different field names
(`order_id`/`productId`/`price` vs `plat_order_num`/`productid`/`amount`),
different success signal (a body field, not an HTTP status, despite what
`docs/server-integration/mobile-iap.md` currently says).

Plan, agreed but **not yet built**:

- Add a new opt-in HMAC strategy to `backend-api`'s `GenericSignatureGameAdapter`
  (alongside the existing `md5_timestamp`/`md5_timestamp_ms`, per-game
  config — existing IAP games keep MD5 unchanged, only a game explicitly
  switched over uses the new one). Today only **og-030** runs Mobile IAP
  at all, so the blast radius of getting this wrong is one game, not many.
- That new strategy sends the **same field names WebPay already uses**
  (`plat_order_num`, `productid`, `amount`, ...) plus one new optional
  field, `channel` (`ios`/`android` for IAP, absent or `web` for WebPay) —
  so a single `onCreateOrder` hook can serve both flows by branching on
  `$request->channel`, instead of needing two separate handlers.
- Only after that ships in `backend-api` does an `Iap` module get added
  here — implementing it against the doc instead of the real
  `md5Timestamp.js` algorithm would repeat the exact mismatch this SDK
  exists to prevent (see `docs/og029-webpay-standard-migration.md`).

## See also

- [Web Payment — PHP SDK](https://docs.alogame.vn/server-integration/webpay-sdk-php) —
  the public-facing integration guide on docs.alogame.vn (start here if
  you're integrating, not developing this package).
- `docs/og029-webpay-standard-migration.md` in api-game — the field-name
  contract this SDK implements verbatim, and the specific bugs it exists to
  make impossible.
