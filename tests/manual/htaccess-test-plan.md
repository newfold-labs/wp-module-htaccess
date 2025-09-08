# Test Plan – `.htaccess` Rule Management (Performance Dashboard)

| **#** | **Test Case** | **Pre-Conditions** | **Steps** | **Expected Result** |
|-------|---------------|---------------------|-----------|----------------------|
| **A. Toggle Functionality (UI → Registry → File)** |
| 1 | Enable a single toggle | Plugin active, `.htaccess` writable | Enable one toggle in dashboard | Rule is added to `.htaccess` (NFD block) and registry |
| 2 | Disable a single toggle | Rule already enabled | Disable that toggle in dashboard | Rule is removed from `.htaccess` and registry |
| 3 | Enable multiple toggles | Plugin active | Enable two or more toggles | All selected rules appear in `.htaccess` and registry |
| 4 | Disable one toggle while others remain | At least 2 toggles enabled | Disable one toggle | Only that rule is removed; other rules remain intact |
| 5 | Disable toggle → reactivation | One rule disabled | Disable a toggle, then trigger `admin_init` or deactivate/reactivate plugin | Disabled rule is **not** re-added |
| 6 | Disable all toggles | All toggles enabled | Disable all toggles | Entire NFD managed block removed from `.htaccess` |
| 7 | UI state lock during operation | Plugin active | Toggle enable/disable | Toggle remains disabled (greyed out) until operation completes |
| **B. Rule Registration & File Sync** |
| 8 | Fragment write logic | Plugin active | Trigger `admin_init` multiple times | Rules are written only once when registered, not on every init |
| 9 | Duplicate marker handling | Existing `.htaccess` rule with same marker | Register new fragment with same marker | Old rule deleted, new managed rule added |
| 10 | Mismatch resolution | Registry and `.htaccess` out of sync | Trigger `admin_init` / plugin activation | Rules are resynced and `.htaccess` updated |
| **C. Plugin Lifecycle** |
| 11 | Plugin deactivation | Plugin active with rules present | Deactivate plugin | Entire NFD managed block removed from `.htaccess` |
| 12 | Plugin reactivation | Plugin deactivated earlier | Reactivate plugin | All registered rules re-applied under NFD block |
| 13 | First-time activation | Fresh install | Activate plugin | All default rules registered and added under NFD block |
| 14 | Activation with existing rules | `.htaccess` already has same markers | Activate plugin | Old rules replaced with new managed rules |
| **D. Error Handling & Recovery** |
| 15 | Remediable bad rule | `.htaccess` has fixable bad rule causing 500 | Run scan | Rule auto-fixed, site recovers |
| 16 | Non-remediable bad rule | `.htaccess` has unfixable bad rule causing 500 | Run scan | Backup restored, site recovers |
| 17 | Missing NFD block | Registry has rules, `.htaccess` block missing | Trigger `admin_init` | NFD block re-created and rules added |
| 18 | File permission error | `.htaccess` not writable | Enable a toggle | Error logged and shown in UI, registry unchanged |
| **E. WP-CLI Commands** |
| 19 | `wp newfold htaccess status` | Plugin active | Run `wp newfold htaccess status` | Outputs combined `diagnose` + `scan`. Shows file validity, HTTP reachability, and NFD block status. |
| 20 | `wp newfold htaccess diagnose` | Plugin active | Run `wp newfold htaccess diagnose` | Reports `file_valid`, `http_status`, and `reachable`. Warns on file issues. |
| 21 | `wp newfold htaccess scan` | Plugin active | Run `wp newfold htaccess scan` | Reports NFD block status, current/expected checksums, and if remediation is possible. |
| 22 | `wp newfold htaccess apply --version=1.0.0` | Plugin active | Run `wp newfold htaccess apply` | Safely writes NFD block; success message if applied or unchanged. |
| 23 | `wp newfold htaccess remediate --version=1.0.0` | Plugin active, block drifted | Run `wp newfold htaccess remediate` | Applies remediation if drift detected; otherwise reports “No remediation needed.” |
| 24 | `wp newfold htaccess restore --version=1.0.0` | Backup exists | Run `wp newfold htaccess restore` | Restores latest full backup, validates file, re-applies NFD block. Reports restored backup and remediation status. |
| 25 | `wp newfold htaccess list_backups` | Backups exist | Run `wp newfold htaccess list_backups` | Lists all available `.htaccess` backups, or reports “No backups found.” |
