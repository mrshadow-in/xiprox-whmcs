# xiProx for WHMCS — Installation & Setup

Sell xiProx **VMs** and **Slot IPs** through WHMCS. The modules talk only to
your **reseller panel API** (`/api/v1/reseller/*`) with your reseller API key —
so **a reseller account is required** ("No reseller panel → no WHMCS support").

- **Tested with:** WHMCS **8.8**, PHP **8.1**
- **Modules:**
  - `xiproxcloud` — VMs (Start / Stop / Restart / Reinstall / Rotate IP + panel SSO)
  - `xiproxslotip` — Slot IPs (Start / Stop / Reset user / Reset password / Rotate IP)
- Slot IPs deployed via WHMCS are **charged to your reseller wallet**, not the customer's.

---

## 1. In the xiProx reseller panel → **API** tab

Everything is managed for you under **Reseller → API → WHMCS API** (you do NOT
use the generic API keys in Settings):

1. **Generate WHMCS API Key** — click it. The key is shown **once**; copy it.
   (This is a managed, reseller-scoped key.)
2. **Suspension webhook:**
   - Enter your **WHMCS webhook URL**:
     `https://<your-whmcs>/modules/servers/xiproxcloud/webhook.php` → **Save**.
   - Click **Generate salt** and copy the **webhook salt**.
3. Step 3 on that page shows exactly what to paste into WHMCS (hostname, etc.).

Keep the **API key** and the **webhook salt** — you'll paste both into WHMCS next.

> API equivalents (if you prefer automation): `POST /api/v1/reseller` key mgmt is
> handled in-panel; webhook via `PUT /api/v1/reseller/whmcs`
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
  (Slot IP): used when the order form doesn't supply those values.

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
   daily cron, and **warns you in the Activity Log when your wallet is low** —
   before customers are affected.

Top the wallet up and the panel emits `vm.resumed`; the service reactivates.

---

## 6. Test it end to end

1. Place an order → **Create** deploys a reseller VM/Slot IP and (if you run a
   white-label panel) creates + assigns the customer.
2. Open the service in the client area → status, IP, specs, **Open Control
   Panel** (one-time SSO), and the action buttons.
3. **Terminate** removes it and refunds the prorated remainder to your wallet.

---

## Object Storage

Planned. A stub lives at [`OBJECT_STORAGE.md`](OBJECT_STORAGE.md); the module
will ship here when the reseller Object Storage API lands.

## Troubleshooting

- **Test Connection fails:** check the hostname (panel URL) and that the API key
  has `reseller:*` scopes and the account is active.
- **Plan dropdown is a text box:** WHMCS couldn't reach the panel while editing
  the product — verify the server, then paste the plan id manually.
- **Webhook rejected (401):** the Access Hash in WHMCS must equal the panel's
  webhook salt exactly. Regenerate in the panel and re-paste.
- Module actions are logged under **Utilities → Logs → Module Log** (the API key
  is never written to the log).
