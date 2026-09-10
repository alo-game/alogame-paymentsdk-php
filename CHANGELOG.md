# Changelog

All notable changes to this package are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows
[SemVer](https://semver.org/).

## [Unreleased]

## [2.1.0] - 2026-09-10

### Added

- `WebPay\Contracts\CharacterListHookInterface` +
  `WebpayHandler::handleGetCharacterList()` — an optional picker for games
  where **one uid owns several characters**, so the player says which one
  receives the top-up. Mirrors the existing `ServerListHookInterface`
  pattern exactly (opt in by implementing the interface; a game that
  doesn't answers `404 NOT_CONFIGURED` automatically) and is orthogonal to
  it: implement either, both, or neither. Unlike the server list, the
  request carries the `uid` Alogame has already validated through
  `onCheckUid`, so it is scoped to that uid (plus `serverId`, when the game
  also has servers) — never to an account. Returning `[]` is a legitimate
  answer, not an error.
- `WebPay\Dto\GetCharacterListRequest` and `WebPay\Dto\CharacterInfo`.
- `WebPay\Dto\CreateOrderRequest::$characterId` — the picked character,
  arriving on the same `onCreateOrder` call as `$serverId`. `null` for
  every game without `CharacterListHookInterface`, so existing
  integrations are unaffected.

### Changed

- `WebpayHandler::VERSION` 1.0.0 -> 1.1.0 — the WebPay wire contract gained
  an endpoint (additive). `handleHealthCheck()` reports it, so support can
  tell whether a partner's deployment supports the character list.
- `scripts/deploy_github.sh` computes the next version by bumping the last
  released one (level inferred from these notes, overridable with
  `--major`/`--minor`/`--patch`) instead of defaulting to the version
  already at the top of this file, asks for confirmation before pushing,
  and refuses to rewrite an already-published tag unless `--retag` is
  passed. The old default re-released the current version and force-pushed
  over its tag, which overwrote the published 2.0.0 on 2026-09-10.

## [2.0.0] - 2026-08-24

### Changed (BREAKING)

- **`Expub\Contracts\ExpubHookInterface::onCheckUid()` removed** — moved to
  a new, optional `Expub\Contracts\CheckUidHookInterface`. Root cause: an
  expub player always reaches checkout by logging into Alogame and picking
  a character from `onGetUserList`'s own response, so uid existence is
  already proven by construction — Alogame's real checkout flow (portal
  top-up and in-game SDK top-up alike) never calls check-uid for an expub
  game. `ExpubHookInterface`'s own docblock previously claimed "all four
  calls are ALWAYS initiated by Alogame," which was never true for this one.
  Verified against `api-game`'s actual dispatcher (`order.service.js`,
  `game.service.js`) and `nap.alogame.vn`'s frontend (`portal-alo`) before
  making this change — neither ever calls it for an expub game.
- **Migration for existing integrators:** if your hooks class already
  implements `onCheckUid()` (some partners' backends had a check-uid
  endpoint before this SDK, e.g. oe-007's `?ac=check_uid`), add
  `implements CheckUidHookInterface` to that same class — the method body
  doesn't change. Without it, `ExpubHandler::handleCheckUid()` now answers
  `404 NOT_CONFIGURED` instead of calling your (still-present) method; this
  is the one silent-breakage risk of this release, so it's a major bump
  despite the interface getting smaller, not bigger.
- Games with no reason to have a check-uid endpoint can simply not
  implement `CheckUidHookInterface` — nothing to migrate.
- Mirrors the existing `WebPay\Contracts\ServerListHookInterface` pattern
  (optional hook, `instanceof` gate in the handler, 404 `NOT_CONFIGURED`
  when absent) rather than inventing a new one.

## [1.1.0] - 2026-08-19

### Added

- `Alogame\PaymentSdk\Expub\*` — a new, independent module implementing the
  older MD5 4-API contract used by exclusive/direct-publishing (expub)
  games (`Signature` header, errors via HTTP status, timestamp in seconds).
  Covers both an expub game's web top-up flow (`ExpubHandler::handleGetUserList`/
  `handleCheckUid`/`handleCreateOrder`/`handlePaymentReceived`) and its
  Mobile IAP purchases, which share `createOrder_url`/`exchange_url` with
  the web flow. Signature scheme verified against `backend-api`'s real
  `md5_timestamp` strategy (`signatures/md5Timestamp.js`), not a doc.
- `ExpubHookInterface`, `Md5SignatureVerifier` — the expub-side counterparts
  to `WebpayHookInterface`/`HmacSignatureVerifier`.
- `examples/SampleExpubHooks.php` + `examples/expub-quickstart.php` — an
  in-memory, end-to-end smoke test for the new module, mirroring the
  existing WebPay quickstart.

## [1.0.0] - 2026-08-18

Package `alo-game/paymentsdk` — named for the payment SDK as a whole, not
just this first contract. `Alogame\PaymentSdk\WebPay\*` is the only module
today; `Alogame\PaymentSdk\Iap\*` is planned (see README's Roadmap), not
part of this release.

### Added

- `WebpayHandler` with `handleCheckUid`, `handleCreateOrder`,
  `handlePaymentReceived` — implements Alogame's HMAC-SHA256 WebPay
  contract (`docs/server-integration/webpay-copub.md` on docs.alogame.vn)
  end to end: signature verification, timestamp freshness, JSON parsing,
  response envelope.
- `WebpayHookInterface` — the three business hooks a game backend
  implements.
- `ServerListHookInterface` + `WebpayHandler::handleGetServerList()` —
  optional fourth hook for a game with multiple servers.
- `WebpayHandler::handleHealthCheck()` — signed liveness/config check,
  independent of real player data.
- `onError` constructor callback — any exception a hook throws is caught
  and turned into a generic `INTERNAL_ERROR` response; the original
  exception is never lost, only kept off the wire.
