# Security Policy

## Reporting a vulnerability

Do **not** open a public GitHub issue for a security report.

Email **security@alogame.vn** with a description and, if possible, a
reproduction. You'll get an acknowledgment within 2 business days.

## In scope

- The signature verification logic in `Alogame\PaymentSdk\WebPay\Signature\HmacSignatureVerifier`
- Any way `WebpayHandler` could call a hook, or answer a request, without a
  valid signature and fresh timestamp

## Out of scope

- Your own `secret_key` handling (storage, rotation) — that's your
  integration's responsibility, this package only verifies against
  whatever secret you pass in
- Vulnerabilities requiring your `secret_key` to already be compromised
