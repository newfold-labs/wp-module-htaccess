# Agent guidance – wp-module-htaccess

This file gives AI agents a quick orientation to the repo. For full detail, see the **docs/** directory.

## What this project is

- **wp-module-htaccess** – A centralized Newfold module for managing .htaccess files: canonical ruleset, validation, no duplicate/conflicting directives, backups, consumer API for other modules to register rewrite fragments. Registers with the Newfold Module Loader; no runtime Composer requires. Maintained by Newfold Labs.

- **Stack:** PHP 7.4+. No runtime Composer deps.

- **Architecture:** Registers with the loader; consumed by wp-module-performance and others. See docs/integration.md.

## Key paths

| Purpose | Location |
|---------|----------|
| Bootstrap | `bootstrap.php` |
| Includes | `includes/` |
| Tests | `tests/` |

## Essential commands

```bash
composer install
composer run fix
composer run lint
```

## Documentation

- **Full documentation** is in **docs/**. Start with **docs/index.md**.
- **CLAUDE.md** is a symlink to this file (AGENTS.md).

---

## Keeping documentation current

When you change code, features, or workflows, update the docs. Keep **docs/index.md** current: when you add, remove, or rename doc files, update the table of contents (and quick links if present). When cutting a release, update **docs/changelog.md**.
