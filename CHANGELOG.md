# Changelog

All notable changes to the xiProx WHMCS modules are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] — 2026-09-15

### Added
- **xiproxcloud** VM provisioning module: MetaData, TestConnection, ConfigOptions
  (live plan dropdown), CreateAccount (idempotent, optional white-label customer),
  Suspend/Unsuspend/Terminate, ChangePackage, ClientArea with Start / Stop /
  Restart / Reinstall / Rotate IP, and one-time-SSO "Login to Panel".
- **xiproxslotip** Slot IP provisioning module (charged to the reseller wallet):
  Start / Stop / Reset user / Reset password / Rotate IP, plus panel SSO.
- **webhook.php** — HMAC-verified inbound receiver for `vm.suspended` /
  `vm.resumed` (±5 min replay window), flips WHMCS service status.
- **hooks.php** — daily reconcile poll (status ⇄ panel) and reseller
  low-wallet-balance admin warning.
- Shared `lib/ApiClient.php` (bearer client, friendly errors, key never logged)
  and `lib/Helper.php` (params, per-service id storage, config accessors).
- `INSTALLATION.md`, `README.md`, and an Object Storage stub.

[1.0.0]: https://example.com/xiprox-whmcs/releases/tag/v1.0.0
