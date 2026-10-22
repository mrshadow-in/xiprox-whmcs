# xiProx for WHMCS — Installation & Setup

Sell xiProx **VMs**, **Slot IPs** and **Web Hosting** through WHMCS. The VM and
Slot IP modules talk only to your **reseller panel API** (`/api/v1/reseller/*`)
with your managed reseller API key — so **a reseller account is required** ("No
reseller panel → no WHMCS support"). The hosting module uses `/api/v1/hosting/*`
and a web-reseller hosting plan — see [Web Hosting](#web-hosting-web-resellers).

- **Tested with:** WHMCS **8.8**, PHP **8.1**
- **Modules:**
  - `xiproxcloud` — VMs (Start / Stop / Restart / Force Stop / Reinstall + panel SSO; IP rotation via Upgrade)
  - `xiproxslotip` — Slot IPs (Start / Stop / Reset user / Reset password + panel SSO; IP rotation via Upgrade)
  - `xiproxhosting` — Web Hosting customer accounts (client area, Hosting Panel SSO, usage)
- `xiproxcloud/` holds the shared client library — install it even if you only sell Slot IPs or hosting.
- Slot IPs deployed via WHMCS are **charged to your reseller wallet**, not the customer's.

---

## 1. In the xiProx reseller panel → **API** tab

Everything is managed for you under **Reseller → API → WHMCS** (you do NOT
use the generic API keys in Settings):

1. **Generate API Key** — click it. The key is shown **once**; copy it.
   (A managed key with the `reseller:*` scopes plus `hosting:read` /
   `hosting:write`; the same key works for Paymenter and Billmora. Keys made
   before hosting support lack the hosting scopes — **Regenerate** to sell hosting.)
2. **Suspension webhook:**
   - Enter your **WHMCS webhook URL**:
     `https://<your-whmcs>/modules/servers/xiproxcloud/webhook.php` → **Save**.
   - Click **Generate salt** and copy the **webhook salt**.
3. Step 3 on that page shows exactly what to paste into WHMCS (hostname, etc.).

Keep the **API key** and the **webhook salt** — you'll paste both into WHMCS next.

> API equivalents (if you prefer automation): the key itself is managed in-panel
> only; the webhook via `PUT /api/v1/reseller/whmcs`
> (`{ "callbackUrl": "…", "regenerateSalt": true }`) and `GET` to read the salt.

---

## 2. Install the module files

Copy the module folders into your WHMCS root, preserving structure:

```
<whmcs>/modules/servers/xiproxcloud/
<whmcs>/modules/servers/xiproxslotip/     ← optional; needs xiproxcloud present (shared lib)
```

`xiproxslotip` includes `xiproxcloud`'s `lib/ApiClient.php` + `lib/Helper.php` via
`require_once`, so **install `xiproxcloud` even if you only sell Slot IPs.**

No ionCube, no Composer, no build step.

---

## 3. Add the Server(s) in WHMCS

**Setup → Products/Services → Servers → Add New Server.** A WHMCS server maps to
ONE module, so add one per module you use (both point at the same panel):

| Field | Value |
| ----- | ----- |
| **Name** | e.g. `xiProx` |
| **Hostname** | your panel URL, e.g. `panel.example.com` |
| **Type / Module** | `xiProx Cloud` (and a second server for `xiProx Slot IP`) |
| **Username** | your reseller account **email** (label only) |
| **Password** | your reseller **API key** (the bearer token) |
| **Access Hash** | your **webhook salt** (verifies inbound webhooks) |
| **Secure** | ✅ (HTTPS) |

Click **Test Connection** — it calls `GET /wallet` and reports your reseller
status + wallet balance. Add each server to a **Server Group** and point your
products at that group.

---

## 4. Create Products

**Setup → Products/Services → Products/Services → Create a New Product**
(type *Server/Provisioning*, module `xiProx Cloud` or `xiProx Slot IP`).

- **Module Settings → Plan:** a dropdown **populated live** from your panel
  (`GET /plans` or `/slot-plans`). If WHMCS can't reach the panel while editing,
  it falls back to a text field — paste the plan id.
- **Default OS / Default IP Pool** (VM) or **Default IP Pool / Default Username**
  (Slot IP): used when the order form doesn't supply those values. For VMs both
  are dropdowns filled from `GET /catalog` when the panel is reachable.

### Let customers choose (optional but recommended)

Add **Custom Fields** (or Configurable Options) on the product with these exact
names so the module reads them at order time:

| Field name | Applies to | Notes |
| ---------- | ---------- | ----- |
| `OS Template` | VM | e.g. `ubuntu-2404`, `debian-12`, `windows-2022` (see `GET /catalog`) |
| `IP Pool` | VM + Slot IP | IP pool id (see `GET /catalog`) |
| `SSH Key` | VM | public key injected at deploy |
| `Username` | Slot IP | proxy username |
| `Password` | Slot IP | proxy password (else WHMCS service password is used) |

The **root/proxy password** defaults to the WHMCS-generated service password, so
Reinstall / Reset Password stay in sync with what WHMCS shows the customer.

### OS template ids (for "Default OS" / the `OS Template` field)

Use these ids as the value of **Default OS** (module setting) or the customer's
`OS Template` field. This is the built-in catalog — the authoritative, live list
is always `GET /api/v1/reseller/catalog` (`osTemplates`).

| Id | Operating system |
| -- | ---------------- |
| `ubuntu-2404` | Ubuntu 24.04 LTS |
| `ubuntu-2204` | Ubuntu 22.04 LTS |
| `debian-12` | Debian 12 |
| `rocky-9` | Rocky Linux 9 |
| `alma-9` | AlmaLinux 9 |
| `windows-2022` | Windows Server 2022 |

**IP pool ids** aren't fixed — they're per-panel. Fetch them from
`GET /api/v1/reseller/catalog` (`ipPools`, each with its `series` + monthly price)
and use the id for **Default IP Pool** / the `IP Pool` field. Leave blank to let
the panel pick the cheapest pool for the plan's series.

---

## 5. Suspension on reseller arrears

If your reseller wallet can't cover a renewal, xiProx suspends the VM and tells
the customer *"your provider hasn't cleared their bill with the datacenter."*
WHMCS learns about it two ways (belt and braces):

1. **Signed webhook** → `webhook.php` (HMAC-verified with your salt, ±5 min
   replay window) flips the service to Suspended/Active instantly.
2. **Cron poll** → `hooks.php` reconciles each service against the panel on the
   WHMCS cron (`AfterCronJob`, every cron run), and **warns you in the Activity
   Log when your wallet is low** —
   before customers are affected.

Top the wallet up and the panel emits `vm.resumed`; the service reactivates.

---

## 6. Day-2 operations

- **Client area (customer):** status, IP, specs, **username + password** (with a
  Show toggle — the root/proxy password tracks the WHMCS service password),
  **Open Control Panel** (one-time SSO), and the action buttons: VM →
  Start / Stop / Restart / Force Stop / Reinstall; Slot IP → Start / Stop /
  Reset user / Reset password.
- **Rotate IP is NOT an instant button** — it's done through the **Upgrade/Config
  path**. Make **`IP Pool`** a configurable option on the product; when the
  customer changes it via *Upgrade/Downgrade Options* and pays, the module
  rotates the IP to the newly selected pool (charged to your reseller wallet).
  Rotation only fires when the pool actually changes.
- **Sync User (admin):** open the service in the WHMCS admin area → **Module
  Commands → "Sync to White-label Panel"**. Use this for services that were
  deployed **before** you set up your white-label panel — it creates (or finds)
  the customer on your panel and assigns this VM/slot to them, so they get panel
  SSO. Idempotent and safe to click again.
- **Renewal & termination:** reseller VMs always auto-renew from your reseller
  wallet at the end of their term. **Terminate** cancels the VM now (prorated
  refund) — except in the **last 5 days** of its term, when the panel refuses it
  (it renews; terminate after that). Set WHMCS to terminate unpaid services
  well before the panel's renewal date, or use the API's `?at=end` cancel.

## 7. Test it end to end

1. Place an order → **Create** deploys a reseller VM/Slot IP and (if you run a
   white-label panel) creates + assigns the customer.
2. Open the service in the client area → status, IP, specs, credentials, **Open
   Control Panel** (one-time SSO), and the action buttons.
3. **Terminate** removes it and refunds the prorated remainder to your wallet.

---

## Web Hosting (web resellers)

For a xiProx **web-reseller hosting plan** (not the VM reseller programme):

1. In xiProx, open your hosting account → **Reseller Centre** and create your
   packages. Your hosting plan must include the *API / WHMCS* privilege.
2. **API key:** if you are also a VM reseller, use the managed key from
   Reseller → API (regenerate it if it predates hosting support). Otherwise
   create one under Settings → API keys with **Web Hosting read + write**.
3. Copy `modules/servers/xiproxhosting/` (and `xiproxcloud/`, whose client
   library it shares) into WHMCS.
4. **Servers → Add New Server**: Module *xiProx Web Hosting*, hostname = your
   xiProx panel host, password = the API key. *Test Connection* lists your
   web-reseller account(s) and checks the plan's API access.
5. **Products**: type *Hosting Account*, module *xiProx Web Hosting*, pick the
   **Package** from the live dropdown. Require a domain on order.
6. **Passwords:** the panel requires **10–128 characters** with no `:` or line
   breaks. Create and Change Password refuse shorter WHMCS passwords with a clear
   error — raise WHMCS's generated password length if needed.
7. WHMCS's daily cron runs **UsageUpdate** (disk + bandwidth per service, matched
   by the stored *xiProx Hosting ID*).

The client area shows the domain, username, status, disk and bandwidth, and a
**Login to Hosting Panel** button that lands the client in their own Hosting
Panel — on your branded panel domain once it's verified in the Reseller Centre.
**Create** is safe to retry: if the panel already hosts that domain on your
reseller account (a lost response), the module adopts that account.

---

## Object Storage

Planned. A stub lives at [`OBJECT_STORAGE.md`](OBJECT_STORAGE.md); the module
will ship here when the reseller Object Storage API lands.

## Troubleshooting

- **Test Connection fails:** check the hostname (panel URL) and that the API key
  is the managed key from Reseller → API (or, for hosting only, a key with the
  `hosting:*` scopes) and the account is active.
- **Plan dropdown is a text box:** WHMCS couldn't reach the panel while editing
  the product — verify the server, then paste the plan id manually.
- **Webhook rejected (401):** the Access Hash in WHMCS must equal the panel's
  webhook salt exactly. Regenerate in the panel and re-paste.
- Module actions are logged under **Utilities → Logs → Module Log** (the API key
  is never written to the log).
