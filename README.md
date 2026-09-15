# xiProx for WHMCS

WHMCS provisioning modules for **xiProx** — sell cloud VMs and Slot IP proxies to
your customers, billed to your reseller wallet. Authenticates against the
xiProx **reseller API** (`/api/v1/reseller/*`); a reseller account is required.

## Modules

- **`xiproxcloud`** — VM provisioning. Create / Suspend / Unsuspend / Terminate,
  Change Package, and client-area **Start / Stop / Restart / Reinstall / Rotate
  IP**, plus one-click **SSO** into your white-label panel. Live plan dropdown.
- **`xiproxslotip`** — Slot IP provisioning. Same lifecycle plus **Reset user /
  Reset password / Rotate IP**. Charged to the reseller wallet. Shares the VM
  module's client library.

## Highlights

- **Live plan dropdown** from the panel — no manual plan mapping.
- **Panel VM/slot id + customer id** stored in per-service custom fields (clean
  WHMCS ↔ panel link); **idempotent Create** survives WHMCS retries.
- **Arrears suspension** surfaced by both a **signed webhook** and a **cron
  poll**, with a customer-facing "provider hasn't cleared their bill" notice and
  an **admin low-balance warning** before customers are affected.
- **Never logs the API key**; friendly panel error messages; HTTPS-only.

## Requirements

- WHMCS **8.8**, PHP **8.1**
- A xiProx reseller account with a `reseller:*`-scoped API key
- (Optional) an active white-label domain for panel SSO

Public, plain PHP — **no ionCube, no bundled secrets.** See
[`INSTALLATION.md`](INSTALLATION.md).

## License

MIT — see [`LICENSE`](LICENSE).
