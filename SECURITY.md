# Security policy

## Supported versions

| Version | Status | Fixes |
|---|---|---|
| 6.x | Stable, active | Features, correctness, and security |
| 5.x | Maintenance | Security and correctness only, for 12 months from the 6.0.0 release |
| ≤ 4.x | End of life | None |

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

Report privately through GitHub's
[private vulnerability reporting](https://github.com/silviooosilva/CacheerPHP/security/advisories/new),
or email the maintainer at `gasparsilvio7@gmail.com` with:

- A description of the issue and its impact.
- Steps or a proof of concept to reproduce it.
- Affected version(s) and configuration (store, encryption, compression).

You will get an acknowledgement within 72 hours and a remediation timeline after
triage. Please allow a reasonable disclosure window before going public; we will
credit reporters who want credit.

## Cryptography notes

- v6 encrypts values with **authenticated AES-256-GCM** and supports key
  rotation via a keyring. Decoding rejects tampered or truncated ciphertext with
  a typed exception; it never returns unauthenticated data. An encrypting
  pipeline also refuses envelopes that declare no encryption, so plaintext
  planted in the backend cannot bypass authentication.
- Without encryption the cache backend is trusted: `PhpSerializer` allows every
  class by default. On a shared backend, enable encryption or restrict classes
  with `new PhpSerializer(allowedClasses: false)`.
- The configured value limit (`withMaxValueBytes`) is enforced on read for every
  pipeline, and decompression stops as soon as it is exceeded.
- v6 never decodes v5's unauthenticated **AES-256-CBC** payloads: a blob that is
  not an authenticated v6 envelope is rejected. Upgrades start with a cold cache
  instead — see [MIGRATION.md](MIGRATION.md#5-cached-data-v6-starts-cold).
- Never cache secrets in a store without encryption enabled, and never log cache
  values — the observability layer records metadata only, and value capture is
  off by default.
