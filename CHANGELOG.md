# Changelog

All notable changes to the xiProx WHMCS modules are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.1] — 2026-10-22

### Fixed
- **Create no longer reports success for a server that doesn't exist.** A VM or Slot IP
  whose setup fails on the panel is rolled back (deleted, and the reseller wallet refunded)
  after WHMCS's Create has already returned. Create used to see the stored id and reply
  "success" without deploying. It now checks the panel: if the stored VM / Slot IP is gone,
  it deploys a fresh one (and links the same customer). A live one is still left alone, so
  WHMCS retries never deploy twice.
- The client area shows a clear "no longer on the panel — contact support" message for such
  a service instead of "VM not found for this reseller".

## [1.1.0] — 2026-10-14

### Added
- **xiproxhosting** module for xiProx web resellers: customer hosting accounts on
  your packages (Create / Suspend / Unsuspend / Terminate / ChangePackage /
  ChangePassword), one-time **Login to Hosting Panel** SSO, a client-area
  overview (status, disk, bandwidth) and UsageUpdate. Backed by the
  `/api/v1/hosting/*` endpoints (`hosting:*` scopes).
- **xiproxcloud:** **Force Stop** client button; **Default OS** and **Default IP
  Pool** are dropdowns filled from `GET /catalog`.
- **Sync User** admin action (VM + Slot IP modules) — link a WHMCS client to the
  reseller white-label panel and assign an existing service to them, for
  services deployed before white-label was set up. Backed by a new
  `POST /api/v1/reseller/slots/[id]/assign` endpoint.
- **Username + password** shown in the client-area manage section (Show toggle);
  tracks the WHMCS service password.
- The managed key from Reseller → API now also carries `hosting:read` /
  `hosting:write`, so one key serves all three modules (regenerate older keys).

### Changed
- **Reseller VMs always auto-renew** (panel change). `CreateAccount` no longer sends
  `autoRenew`. `TerminateAccount` still cancels the VM now with the usual prorated
  refund, but the panel refuses it in the **last 5 days** of the VM's term (the
  error shows on the service — retry after it renews).
- **IP rotation moved off the instant client button to the Upgrade/Config path**:
  change the `IP Pool` configurable option via WHMCS Upgrade/Downgrade → the
  module rotates to the new pool (only when it actually changes). The deploy pool
  is remembered in a per-service custom field.
- Slot IP and hosting calls are logged under their own module names in the WHMCS
  module log (they were all logged as `xiproxcloud`).

### Fixed
- **xiproxhosting:** Login to Hosting Panel no longer redirects to an empty URL;
  Suspend / Unsuspend of an account already in that state counts as done;
  passwords the panel would refuse (under 10 characters, or containing `:`) get a
  clear error; a retried Create adopts the account a lost response left behind
  instead of failing; UsageUpdate matches services by the stored hosting id, not
  the domain; Test Connection also checks the plan's API access.

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
- **hooks.php** — reconcile poll on the WHMCS cron (status ⇄ panel) and reseller
  low-wallet-balance admin warning.
- Shared `lib/ApiClient.php` (bearer client, friendly errors, key never logged)
  and `lib/Helper.php` (params, per-service id storage, config accessors).
- `INSTALLATION.md`, `README.md`, and an Object Storage stub.
