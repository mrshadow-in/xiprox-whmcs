# xiProx for WHMCS

WHMCS provisioning modules for **xiProx** — sell cloud VMs, Slot IP proxies and
web hosting to your customers. VMs and Slot IPs are billed to your reseller
wallet through the xiProx **reseller API** (`/api/v1/reseller/*`; a reseller
account is required); hosting accounts come from your web-reseller plan
(`/api/v1/hosting/*`).

## Modules

- **`xiproxcloud`** — VM provisioning. Create / Suspend / Unsuspend / Terminate,
  Change Package, and client-area **Start / Stop / Restart / Force Stop / Reinstall**, plus
  one-click **SSO** into your white-label panel. Live plan, OS and IP-pool dropdowns. **IP rotation
  via the Upgrade/Config path** (change the `IP Pool` option). Admin **Sync User**
  action to link pre-white-label services to a customer.
- **`xiproxslotip`** — Slot IP provisioning. Same lifecycle plus **Reset user /
  Reset password**, IP rotation via Upgrade/Config. Charged to the reseller
  wallet. Shares the VM module's client library.
- **`xiproxhosting`** — Web hosting for **web resellers** (a xiProx hosting plan
  with reseller features). Each service is a customer account on one of your
  packages: Create / Suspend / Unsuspend / Terminate / Change Package / Change
  Password, a client-area overview (status, disk, bandwidth), **Login to Hosting
  Panel** (one-time SSO, on your branded domain when set up) and disk/bandwidth
  **UsageUpdate**. Uses the managed key or a key with the `hosting:read` +
  `hosting:write` scopes.

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
- A xiProx reseller account and its managed API key (Reseller → API); for
  hosting only, a web-reseller hosting plan with API access
- (Optional) an active white-label domain for panel SSO

Public, plain PHP — **no ionCube, no bundled secrets.** See
[`INSTALLATION.md`](INSTALLATION.md).

## License

MIT — see [`LICENSE`](LICENSE).
