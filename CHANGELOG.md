# Changelog

## 0.1.0

Initial alpha release implementing Nylon Pay SDK Spec v2.0.0.

### Added

- Factory `createNylonPay()` with secret-aware singleton cache
- All 11 operations: collect, payout, resolve variants, status, transaction, list, verify phone, invoice, webhook verification
- `PaymentInstance` with `on` / `once` / `off` / `wait` lifecycle
- HMAC-SHA256 request signing with RFC 8785 JCS canonical payloads
- Response signature verification with `_requestNonce` binding (D21)
- Streamed 10 MiB response size cap enforced during read (S17)
- Webhook verification with replay protection and lowercase-hex enforcement
- Canonical security suite S1–S18 and integration suite I1–I19
- Zero runtime Composer dependencies (`ext-curl` transport)
