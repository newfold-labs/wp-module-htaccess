# wp-module-htaccess

A centralized Newfold WordPress module for managing `.htaccess` rules safely and consistently.

Instead of letting multiple modules/plugins inject their own rules, this module provides a **single source of truth** for Newfold-specific directives (NFD).  
It writes only to a managed section in `.htaccess` (`# BEGIN NFD Htaccess … # END NFD Htaccess`), leaving the WordPress core rules and host rules untouched.

---

## Features

- **Fragment API**: Other modules/plugins can register their own `.htaccess` fragments.
- **Canonical Section**: All registered fragments are composed into one canonical block under `# BEGIN NFD Htaccess`.
- **Header + Checksum**: Each block has metadata including:
  - `# Managed by Newfold Htaccess Manager vX.Y (host)`
  - `# STATE sha256: <hash> applied: <timestamp>`
- **Exclusivity**: Fragments can declare themselves exclusive to prevent duplicate rules.
- **Validation & Remediation**: Common errors (unbalanced `IfModule`, duplicate markers, etc.) are detected and remediated.
- **Safe Merge**: Uses WordPress’ `insert_with_markers()` to update only the NFD section, preserving all other rules.
- **No-op Writes**: If nothing changes (checksum match), no update is written — prevents unnecessary churn or backup spam.
- **Pluggable**: Designed as a standard Newfold module, wired through the Module Loader.

---

## Fragments

A **Fragment** is a small PHP class that implements the `Fragment` interface:

```php
class DemoHeader implements Fragment {
    public function id()        { return 'nfd.demo-header'; }
    public function priority()  { return self::PRIORITY_POST_WP; }
    public function exclusive() { return true; }
    public function is_enabled( $context ) { return true; }
    public function render( $context ) {
        return "# BEGIN NFD Demo Header\n<IfModule mod_headers.c>\nHeader set X-NFD-Demo \"Hello\"\n</IfModule>\n# END NFD Demo Header";
    }
}
```

When registered via the API, this fragment is automatically included in the managed NFD block.

---

## 🚀 Usage

### Register a Fragment

In your module/plugin:

```php
use NewfoldLabs\WP\Module\Htaccess\Api;
use NewfoldLabs\WP\Module\Htaccess\Fragments\DemoHeader;

Api::register( new DemoHeader(), false );

// Queue a one-time apply (e.g. on activation or admin_init).
Api::queue_apply( 'bootstrap' );
```

### Result in `.htaccess`

```apache
# BEGIN NFD Htaccess
# Managed by Newfold Htaccess Manager v1.0.0 (example.com)
# STATE sha256: 34abc... applied: 2025-08-27T15:35:00Z

# BEGIN NFD Demo Header
<IfModule mod_headers.c>
Header set X-NFD-Demo "Hello"
</IfModule>
# END NFD Demo Header

# END NFD Htaccess
```

### WordPress block remains intact

```apache
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
...
</IfModule>
# END WordPress
```

---

## Development

1. Add the module to your brand plugin via Composer:

   ```bash
   composer require newfold-labs/wp-module-htaccess:@dev
   ```

2. Register in the plugin bootstrap:

   ```php
   register( array(
       'name'     => 'wp-module-htaccess',
       'label'    => __( 'Htaccess', 'wp-module-htaccess' ),
       'callback' => function ( Container $container ) {
           new Manager( $container ); // bootstraps the module
       },
       'isActive' => true,
       'isHidden' => true,
   ));
   ```

3. Drop your fragments into `includes/Fragments/`.
