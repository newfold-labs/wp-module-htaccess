---
name: wp-module-htaccess
title: Integration
description: How the module registers and integrates.
updated: 2026-11-27
---

# Integration

The module registers with the Newfold Module Loader via bootstrap.php. Other modules (e.g. wp-module-performance) use its API to register .htaccess fragments. The host plugin typically depends on it when using performance or other modules that need .htaccess rules.

## Third-party block formats

Before writing, the validator checks that BEGIN/END markers balance. Some plugins open their block without `# BEGIN`. These are accepted when listed in `Validator::KNOWN_BLOCK_FORMATS` (opening comment text => END label). It ships with Wordfence (`# Wordfence WAF` ... `# END Wordfence WAF`).

Use the `nfd_htaccess_known_block_formats` filter to change the list. The array you return replaces the defaults, so add to `$formats` rather than returning a new array. An empty array turns the list off, and anything other than an array falls back to the defaults. Matching is exact and case-sensitive.

```php
add_filter( 'nfd_htaccess_known_block_formats', function ( $formats ) {
	$formats['Acme Rules'] = 'Acme Rules'; // "# Acme Rules" ... "# END Acme Rules"
	return $formats;
} );
```
