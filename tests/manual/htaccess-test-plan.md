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
| **E. WP-CLI Tests** |
| 19 | Test WP-CLI | Plugin active | Run CLI commands (add/remove/list/sync) | CLI behaves consistently with UI (registry + `.htaccess` update correctly) |
