# Changelog

All notable changes to the xiProx WHMCS modules are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- **Sync User** admin action (both modules) — link a WHMCS client to the reseller
  white-label panel and assign an existing service to them, for services
  deployed before white-label was set up. Backed by a new
  `POST /api/v1/reseller/slots/[id]/assign` endpoint.
- **Username + password** shown in the client-area manage section (Show toggle);
  tracks the WHMCS service password.

### Changed
- **IP rotation moved off the instant client button to the Upgrade/Config path**:
  change the `IP Pool` configurable option via WHMCS Upgrade/Downgrade → the
  module rotates to the new pool (only when it actually changes). The deploy pool
  is remembered in a per-service custom field.

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
