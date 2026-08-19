# Changelog

All notable changes to this package are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows
[SemVer](https://semver.org/).

## [Unreleased]

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
