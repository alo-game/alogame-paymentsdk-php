# Alogame Payment SDK (PHP)

Server-side SDK for a game backend integrating with Alogame's payment
flows. Ships two independent modules today:

- **`Alogame\PaymentSdk\WebPay`** — the HMAC-SHA256 contract for
  co-publishing (co-pub) games. See [Usage](#usage).
- **`Alogame\PaymentSdk\Expub`** — the MD5 contract for exclusive/direct-
  publishing (expub) games, covering both web top-up **and** Mobile IAP
  (they share `createOrder_url`/`exchange_url`). See [Expub](#expub-exclusivedirect-publishing-games).

Pick the module matching your game's publishing model — the two are not
interchangeable and not versions of each other; see each module's own
section for which applies to you. Either way the SDK handles signature
verification, timestamp freshness, JSON parsing, and the exact response
shape Alogame expects — a game backend only implements a handful of
business hooks and never touches raw HTTP, signing, or field names.

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

1. **Get your credentials from Alogame** — a shared HMAC `secret_key` (the
   only credential `WebpayHandler` actually takes; `game_code` never appears
   on the wire or in this SDK's API, so there's nothing to get for it), plus
   which environment (dev/staging vs prod) will call you first. You don't
   generate the secret; Alogame issues it.
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

## Optional: several characters per UID

Only implement this if **one uid of yours can own more than one character**
and the player must pick which one receives the top-up. Independent of the
server list above — implement either, both, or neither. Alogame derives
"does this game need a character picker" from whether your hooks object
implements this interface:

```php
use Alogame\PaymentSdk\WebPay\Contracts\CharacterListHookInterface;
use Alogame\PaymentSdk\WebPay\Dto\{CharacterInfo, GetCharacterListRequest};

final class MyGameHooks implements WebpayHookInterface, CharacterListHookInterface
{
    // ...onCheckUid/onCreateOrder/onPaymentReceived as above...

    public function onGetCharacterList(GetCharacterListRequest $request): array
    {
        return array_map(
            static fn ($character) => new CharacterInfo($character->id, $character->name),
            MyCharacterRepository::forUid($request->uid, $request->serverId),
        );
    }
}
```

Called only after `onCheckUid` has already accepted the uid, so there is no
not-found case to signal — returning `[]` just means "this uid owns no
character" and shows an empty picker rather than an error.

Wire a route to `WebpayHandler::handleGetCharacterList()`. The picked value
then arrives on the **same** `onCreateOrder` call as everything else, as
`$request->characterId` — deliver to that character rather than to whatever
the uid alone resolves to. It is `null` for every game without this
interface, so an existing integration sees no change.

## Optional: characters linked to the player's Alogame account

Implement this if your players **log into their Alogame account** and your
backend links their characters to that account — Alogame then lists those
characters itself instead of asking the player to type a uid. This is the
HMAC equivalent of Expub's `onGetUserList` below: if you already serve IAP or
webpay through `WebpayHandler`, use this one, not `ExpubHandler` — the
latter verifies MD5 with a body `timestamp`, which Alogame never sends to a
game configured for HMAC (every such request fails as `timestamp` missing).

```php
use Alogame\PaymentSdk\WebPay\Contracts\UserListHookInterface;
use Alogame\PaymentSdk\WebPay\Dto\{GetUserListRequest, UserCharacter};

final class MyGameHooks implements WebpayHookInterface, UserListHookInterface
{
    // ...onCheckUid/onCreateOrder/onPaymentReceived as above...

    public function onGetUserList(GetUserListRequest $request): array
    {
        return array_map(
            static fn ($c) => new UserCharacter($c->uid, $c->name, $c->serverName),
            MyCharacterRepository::linkedToAlogameUser($request->userId),
        );
    }
}
```

`$request->userId` is the Alogame account's **numeric id** — the `userId`
the Alogame client SDK returns at login, not its uuid. Returning `[]` means
"no linked character" and shows an empty list rather than an error.

Wire a route to `WebpayHandler::handleGetUserList()`. Response on success:
`{"errcode":0,"data":[{"uid":"...","characterName":"...","server":"..."}]}`
(`server` is omitted when you pass `null`).

## Health check — "is my SDK actually listening?"

Wire the last route to `WebpayHandler::handleHealthCheck()`. Alogame calls
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

## Expub (Exclusive/Direct-Publishing games)

For **expub games only** — co-pub games use `WebPay` above instead. This is
the older of the two contracts (MD5, `Signature` header, errors reported via
HTTP status rather than an `errcode` field), matching the algorithm every
expub/legacy game provider already signs with on Alogame's side
(`backend-api`'s `md5_timestamp` strategy). It covers **both** an expub
game's web top-up flow and its Mobile IAP purchases — the two channels share
`createOrder_url`/`exchange_url` entirely.

Implement the one interface:

```php
use Alogame\PaymentSdk\Expub\Contracts\ExpubHookInterface;
use Alogame\PaymentSdk\Expub\Dto\{
    GetUserListRequest, UserCharacter,
    CheckUidRequest, CheckUidResult,
    CreateOrderRequest, CreateOrderResult,
    PaymentReceivedRequest, PaymentReceivedResult,
};

final class MyExpubGameHooks implements ExpubHookInterface
{
    public function onGetUserList(GetUserListRequest $request): array
    {
        // Every character linked to this Alogame account — the player
        // picks one on Alogame's side before topping up. Empty is valid
        // (account linked, no characters yet).
        return MyCharacterRepository::findAllByAlogameUserId($request->userId);
    }

    public function onCheckUid(CheckUidRequest $request): CheckUidResult
    {
        $character = MyCharacterRepository::findByUid($request->uid);

        return $character
            ? CheckUidResult::found($character->name, $character->server)
            : CheckUidResult::notFound();
    }

    public function onCreateOrder(CreateOrderRequest $request): CreateOrderResult
    {
        // $request->osId is "ios"/"android" for a Mobile IAP purchase, null
        // for a web top-up — same method, branch on it if delivery differs.
        $existing = MyOrderRepository::findByAlogameOrderId($request->orderId);
        if ($existing !== null) {
            // Alogame retried — return the SAME order_code, never a new one.
            return CreateOrderResult::duplicate($existing->orderCode);
        }

        $order = MyOrderRepository::create(
            alogameOrderId: $request->orderId,
            uid: $request->uid,
            productId: $request->productId,
        );

        return CreateOrderResult::created($order->id);
    }

    public function onPaymentReceived(PaymentReceivedRequest $request): PaymentReceivedResult
    {
        $order = MyOrderRepository::findByOwnId($request->orderCode);
        if ($order === null) {
            return PaymentReceivedResult::orderCodeNotFound();
        }

        MyInventory::deliver($order->uid, $order->productId);

        return PaymentReceivedResult::ok();
    }
}
```

Wire four routes to it — same reasoning as `WebPay` above (this SDK is
framework-agnostic, so dispatching the right URL to the right method is the
one piece of plumbing left to you):

```php
use Alogame\PaymentSdk\Expub\ExpubHandler;

$handler = new ExpubHandler(
    secret: getenv('ALOGAME_EXPUB_SECRET'),
    hooks: new MyExpubGameHooks(),
    onError: fn (\Throwable $e) => MyLogger::error($e),
);

$response = match (true) {
    $_SERVER['REQUEST_URI'] === '/expub/get-user-list'    => $handler->handleGetUserList(getallheaders(), file_get_contents('php://input')),
    $_SERVER['REQUEST_URI'] === '/expub/check-uid'        => $handler->handleCheckUid(getallheaders(), file_get_contents('php://input')),
    $_SERVER['REQUEST_URI'] === '/expub/create-order'     => $handler->handleCreateOrder(getallheaders(), file_get_contents('php://input')),
    $_SERVER['REQUEST_URI'] === '/expub/payment-received' => $handler->handlePaymentReceived(getallheaders(), file_get_contents('php://input')),
};

http_response_code($response->status);
header('Content-Type: application/json');
echo json_encode($response->body);
```

There is no health-check route for this contract (unlike `WebPay`) — Alogame
doesn't call one for expub games today.

See `examples/SampleExpubHooks.php` and `examples/expub-quickstart.php` for
a runnable, in-memory version of all four calls, including the retry/
duplicate-order_id and Mobile-IAP-vs-web-top-up cases.

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

1. Bump `WebpayHandler::VERSION`/`ExpubHandler::VERSION` (whichever module
   changed), add a matching entry to `CHANGELOG.md`.
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

**Expub's own Mobile IAP is already covered** — see [Expub](#expub-exclusivedirect-publishing-games)
above; `Expub\ExpubHandler::handleCreateOrder`/`handlePaymentReceived` serve
both an expub game's web top-up and its IAP purchases today, both MD5.

**Still not built: unifying co-pub's Mobile IAP onto `WebPay`'s HMAC
contract.** Mobile IAP for a *co-pub* game currently lives entirely in
`backend-api` (`GenericSignatureGameAdapter` + `signatures/md5Timestamp.js`)
— same MD5 algorithm as `Expub` above, but different field names
(`order_id`/`productId`/`price` vs `WebPay`'s `plat_order_num`/`productid`/`amount`),
and a different success signal (a body field, not `errcode`).

Plan, agreed but **not yet built**:

- Add a new opt-in HMAC strategy to `backend-api`'s `GenericSignatureGameAdapter`
  (alongside the existing `md5_timestamp`/`md5_timestamp_ms`, per-game
  config — existing co-pub IAP games keep MD5 unchanged, only a game
  explicitly switched over uses the new one). Very few co-pub games run
  Mobile IAP at all today, so the blast radius of getting this wrong is
  small.
- That new strategy sends the **same field names `WebPay` already uses**
  (`plat_order_num`, `productid`, `amount`, ...) plus one new optional
  field, `channel` (`ios`/`android` for IAP, absent or `web` for WebPay) —
  so a single `onCreateOrder` hook can serve both flows by branching on
  `$request->channel`, instead of needing two separate handlers.
- Only after that ships in `backend-api` does this get folded into `WebPay`
  here — implementing it against a doc instead of the real
  `md5Timestamp.js` algorithm would repeat the exact mismatch this SDK
  exists to prevent (see `docs/og029-webpay-standard-migration.md` in
  api-game).

## See also

- [Web Payment — PHP SDK](https://docs.alogame.vn/server-integration/webpay-sdk-php) —
  the public-facing integration guide on docs.alogame.vn (start here if
  you're integrating, not developing this package).
- `docs/og029-webpay-standard-migration.md` in api-game — the field-name
  contract this SDK implements verbatim, and the specific bugs it exists to
  make impossible.
