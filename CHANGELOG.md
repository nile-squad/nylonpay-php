# Changelog

## 0.1.1

Upgrading from 0.1.0. **Upgrade if your metadata keys are not plain ASCII.**

### Fixed, critical

- **Requests with non-Latin metadata keys failed authentication.** The canonical
  payload sorted object keys by UTF-16 **little-endian** bytes, which is not
  UTF-16 code-unit order: little-endian compares the low byte first, so `"Ā"`
  (U+0100) sorted before `"Z"` (U+005A) where the correct order is the reverse.
  Any sibling key set containing a character whose low byte is below `0x20`, Cyrillic, CJK, Latin Extended, emoji, was canonicalized differently from the
  server, so a correctly-formed request was rejected as an authentication
  failure. Sorting is now by UTF-16 **big-endian** bytes, which is equivalent to
  code-unit order.

  Pure-ASCII payloads were never affected, so most integrations saw nothing.

### Fixed

- An empty JSON object in a payload now canonicalizes as `{}` instead of `[]`.
  PHP's `[]` is both an empty list and an empty map; pass an `stdClass` (or any
  object) where an empty JSON object is meant and it now survives sorting and
  serialization intact. Nested objects passed as `stdClass` are sorted too.
- Numeric-string object keys such as `"0"`, which PHP silently converts to an
  int, no longer reach the key comparator as an int.

### Added

- The spec's canonical signing conformance vectors V1–V7 now ship as a unit test
  (spec requirement S19), pinning this SDK to the backend rather than only to
  itself. V7 covers the ordering bug above.

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
