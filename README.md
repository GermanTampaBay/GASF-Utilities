# GASF Utilities

All custom **germantampabay.com** functionality in one update-safe plugin:
native SEO + AI event descriptions, branded short links, redirects + 404 log,
native Instagram feed, reviews wall, Facebook token watchdog, performance and
hardening tweaks, and a stack of content shortcodes. (Home-page heroes moved to
the **GASF-Events** plugin — Events → Heroes / Recurring Heroes.)

## How it's put together

- `gasf-mec-importer.php` — thin loader: shared helpers (`gasf_site_enabled()`,
  `gasf_mec_log()`, the `gasf_util_lock()` family), a glob that requires every
  `modules/*.php` in filename order (the numeric prefix IS the load order —
  a module may only call helpers from lower-numbered files), and the GitHub
  auto-update hooks. *The filename is historical (the plugin began as
  the MEC importer fixes); renaming it would break the mu-plugin shim on the
  main site and the plugin path on the Krampus install, so it stays.*
- `modules/*.php` — one self-contained utility per file. Each module
  **self-gates** on a wp_option (`gasf_site_enable_*` / `gasf_mec_enable_*`,
  default ON, `'0'` = off) and, if it has a UI, registers a tab on the single
  **GASF Utilities** admin page via `gasf_utilities_add_tab()`.
- **Settings tab** (`modules/02-settings.php`) — the switchboard: an on/off
  toggle for every gate (its registry must list every gate and every cron a
  module schedules) plus site-wide settings shared by multiple modules: the
  Anthropic API key and model (`gasf_anthropic_key` / `gasf_anthropic_model`).
  CLI equivalent: `wp option update <gate> 0`.
- **Claude client** (`modules/03-claude.php`) — the only code that calls
  Anthropic: `gasf_anthropic_key()`, `gasf_anthropic_model()`,
  `gasf_anthropic_models()` (live list for the Settings dropdown, cached 12 h)
  and `gasf_claude_text()`. New AI utilities call these; nothing else should
  build an Anthropic request.

## Where it runs

| Site | How | Deploy |
|---|---|---|
| germantampabay.com (main) | git working copy at `/home4/germanta/gasf-muplugin`, loaded by mu-plugin shim `wp-content/mu-plugins/gasf-mec-importer.php` (see `deploy/mu-plugins-loader.php`) | `git pull` in that dir — live immediately. **Lint first**: `git show origin/main:<file> \| php -l` (a syntax error fatals the whole site) |
| germantampabay.com/krampus | regular plugin `wp-content/plugins/GASF-Utilities/`, WP auto-update from this repo (`Update URI` mechanism) | bump the `Version:` header, push, then WP auto-updates (twice daily) or `wp plugin update GASF-Utilities` |

Secrets are **never** in this repo — every token/key lives in wp_options and
is read at runtime.

## Logging

Best-effort, size-capped log at `<parent-of-ABSPATH>/gasf-mec-importer.log`
(outside the web root), rotating at 1 MB.

## History

The plugin started (2026-06) as an update-safe consolidation of the Code
Snippets that fixed the Modern Events Calendar + Advanced Importer Facebook
sync (duplicate sweep, native recurrence, cron fix, …). MEC was retired on
both sites in mid-2026, and the MEC-era modules were removed in **v1.3.0**
(2026-07) — the code lives on in git history if archaeology is ever needed.
