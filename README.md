<a href="https://newfold.com/" target="_blank">
    <img src="https://newfold.com/content/experience-fragments/newfold/site-header/master/_jcr_content/root/header/logo.coreimg.svg/1621395071423/newfold-digital.svg" alt="Newfold Logo" title="Newfold Digital" align="right" 
height="42" />
</a>

# WordPress Htaccess Module
[![Version Number](https://img.shields.io/github/v/release/newfold-labs/wp-module-htaccess?color=21a0ed&labelColor=333333)](https://github.com/newfold-labs/wp-module-htaccess/releases)
[![License](https://img.shields.io/github/license/newfold-labs/wp-module-htaccess?labelColor=333333&color=666666)](https://raw.githubusercontent.com/newfold-labs/wp-module-htaccess/master/LICENSE)

A module for managing `.htaccess` rules in WordPress through a unified fragment-based system.

## Module Responsibilities

- Provides a **centralized state-based manager** for `.htaccess` rules instead of ad‑hoc rule injection by multiple modules.
- Exposes a **fragment registration API** that other modules can use to safely register or unregister rules (e.g., performance, SSL, skip‑404).
- Ensures only **one canonical write** per request (debounced on `shutdown`), preventing duplicate or conflicting blocks.
- Validates `.htaccess` syntax using a **Validator service** before applying changes to avoid HTTP 500 errors.
- Maintains **backups** of the `.htaccess` file before changes are applied. Backups are timestamped.
- Provides a **Scanner** that can:
  - Diagnose whole‑file validity (BEGIN/END balance, IfModule balance, etc.).
  - Perform loopback HTTP checks to detect fatal 500 errors caused by `.htaccess` corruption.
  - Verify and remediate only the Newfold‑managed blocks (NFD Htaccess section).
  - Restore from the latest backup if corruption is detected.
- Ships with a **WP‑CLI command** (`wp nfd-htaccess …`) for operators and support to inspect, remediate, restore, or list backups.
- Designed to coexist with WordPress Core’s own `# BEGIN WordPress` rules—this module manages **only the NFD blocks**.

## Features

- **Fragment Registry**  
  Other modules register fragments by providing an ID, marker, and render function.  
  Example: Force HTTPS, Browser Cache rules, Skip 404 rules.

- **Updater**  
  Writes the canonical state into `.htaccess` with markers, a checksum header, and metadata.  
  Uses WordPress’ `insert_with_markers()` and ensures file permissions and paths are respected.

- **Validator**  
  Lightweight checks (balanced tags, valid markers, no malformed Rewrite conditions).  
  Provides auto‑remediation for common issues.

- **Scanner**  
  - `diagnose()`: validates the whole file and performs an HTTP HEAD request to detect 500s.  
  - `scan()`: inspects only the NFD block and verifies checksum.  
  - `remediate()`: re‑applies the canonical NFD block if drift is detected.  
  - `restore_latest_backup_verified()`: restores the last `.bak`, validates, and re‑applies the NFD block.  
  Can be run periodically via cron.

- **Backups**  
  Each write to `.htaccess` creates a timestamped `.htaccess.YYYYMMDD-HHMMSS.bak` in the site root.

- **WP‑CLI Integration**  
  Subcommands include:  
  - `wp nfd-htaccess status` – combined diagnose + scan summary  
  - `wp nfd-htaccess diagnose` – whole‑file + HTTP reachability  
  - `wp nfd-htaccess scan` – NFD block only  
  - `wp nfd-htaccess apply` – apply current fragments immediately  
  - `wp nfd-htaccess remediate` – scan + apply if drift detected  
  - `wp nfd-htaccess restore` – restore from latest backup, validate, re‑heal NFD  
  - `wp nfd-htaccess list-backups` – list all available backups

## Critical Paths

- When a module registers/unregisters a fragment, the `.htaccess` update is **debounced** and applied safely at the end of the request (shutdown).  
- The Validator ensures that only valid rules are written; invalid fragments are auto‑remediated or skipped.  
- Backups are created before every write.  
- Scanner + CLI allow support engineers to restore or remediate if customers hit a 500.  
- REST/AJAX/CLI contexts are considered **safe** for shutdown‑apply, ensuring API‑driven changes (like cache level updates) are reflected in `.htaccess`.

## Installation

### 1. Add the Newfold Satis to your `composer.json`.

```bash
composer config repositories.newfold composer https://newfold-labs.github.io/satis
```

### 2. Require the `newfold-labs/wp-module-htaccess` package.

```bash
composer require newfold-labs/wp-module-htaccess
```

### 3. Register the module with the Newfold module loader.

```php
use NewfoldLabs\WP\ModuleLoader\Container;
use NewfoldLabs\WP\Module\Htaccess\Manager;
use function NewfoldLabs\WP\ModuleLoader\register;

add_action(
    'plugins_loaded',
    function () {
        register(
            array(
                'name'     => 'wp-module-htaccess',
                'label'    => __( 'Htaccess', 'wp-module-htaccess' ),
                'callback' => function ( Container $container ) {
                    $manager = new Manager( $container );
                    $manager->boot();
                },
                'isActive' => true,
                'isHidden' => true,
            )
        );
    }
);
```

### 4. Other modules register fragments through the API

```php
use NewfoldLabs\WP\Module\Htaccess\Api;
use MyPlugin\Htaccess\Fragments\ForceHttps;

Api::register( new ForceHttps() );
```

## Release

Run the Newfold Prep Release GitHub Action to automatically bump the version (patch, minor or major), update build files, and language files. It will create a PR with changed files for review.

## References

- [Newfold WordPress Module Loader](https://github.com/newfold-labs/wp-module-loader)  
- [Newfold Features Modules](https://github.com/newfold-labs/wp-module-features)

## TODO

- Cron job to periodically run `Scanner::diagnose()` and auto‑remediate if needed.
- Expand Validator with more syntax checks.
- More built‑in fragments (e.g., force HTTPS, security headers).
