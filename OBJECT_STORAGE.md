# xiProx Object Storage for WHMCS — (planned)

Object Storage provisioning through WHMCS is **on the roadmap**, not yet shipped.

When the reseller Object Storage API lands (`/api/v1/reseller/object/*`), a third
module — `modules/servers/xiproxobject/` — will be added here with the same
shape as the VM / Slot IP modules:

- **ConfigOptions:** live plan dropdown from the reseller Object Storage catalog.
- **CreateAccount:** provision a bucket/tenant, charged to the reseller wallet.
- **Client area:** access keys, endpoint, usage, and panel SSO.
- Suspension via the same signed webhook + cron-poll safety net.

No action is needed today. Watch the CHANGELOG for the release.
