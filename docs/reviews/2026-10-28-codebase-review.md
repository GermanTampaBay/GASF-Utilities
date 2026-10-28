# GASF Utilities — codebase review (2026-10-28)

Scope: every file in the repo (`gasf-mec-importer.php`, `deploy/`, all 28 modules,
the 404 template, README, .gitignore) — 7,500 lines. All files pass `php -l`.
No code was changed in this review; each item cites `file:line` on commit `c0a53aa`.

**Status (same day, on this branch):** v2.7.0 fixed 1.1–1.3, 2.1–2.5 and
the model id (§6, now a dropdown). v2.8.0 did the rest: 1.4/4.3 (festival
dates come from the calendar), 1.5, 1.6, 2.6–2.11, 3.1–3.8 (the Overview is
generated from the registry, the tab bar follows it, the hidden FB→Blog switch
is a checkbox), 4.1 (state options split from config, with one-time migration),
4.2 (modules renamed `12-parking`, `16-world-cup-schedule`, `44-pdf-to-png`;
README states the load-order rule), 4.4–4.9, and every §5 item. The Yoast
sweep (2.8) runs exactly once more on the first admin load and is then dead —
delete `gasf_seo_import_from_yoast()` once both sites carry the
`gasf_seo_yoast_migrated` option. **Left as it was, on purpose:** 3.10 (the
parking block's default map) — removing the default breaks the five pages
that rely on it until each passes `map=` explicitly; that is a content edit on
the live site, not a code change. 1.7's unused `GASF_SITE_VERSION` also stays,
in case anything outside this repo checks it. Line numbers below refer to
`c0a53aa`, before any of this.

Overall: the code is well above average for a WordPress plugin. Inputs are
nonced and sanitized, output is escaped, secrets are autoload-off, the
concurrency bugs that bit in the past have been fixed properly. The problems are
mostly **drift** — three copies of the same thing, a Settings page that doesn't
know about two modules, an Overview page that describes a plugin from six
releases ago, and documentation that over-claims. Below, worst first.

---

## 1. Bugs (wrong today)

| # | What | Where | Why it matters |
|---|---|---|---|
| 1.1 | **Two modules cannot be switched off from the Settings tab.** `gasf_site_enable_ghours` (Google Hours) and `gasf_site_enable_pdf2png` (PDF→PNG) exist as gates but are missing from `gasf_settings_registry()`. | `modules/02-settings.php:48-83`; gates at `39-google-hours.php:23`, `38-pdf-to-png.php:14` | The Settings page is documented as "the switchboard: an on/off toggle for every gate". It isn't. Only `wp option update` can turn these off. |
| 1.2 | **Orphan crons when FB→Blog or Image Compressor is gated off.** The gate-off cron reconciliation map lists four hooks. `gasf_fbs_cron` (hourly) and `gasf_imgc_cron` (4-hourly) are not in it. A module that is gated off never loads, so it can't clear its own cron — the comment on line 203 explains exactly this failure mode, then misses two cases. (The comment says imgc "reconciles its own cron" — only while the module loads.) | `02-settings.php:210-215`; crons at `40-fb-share.php:402`, `38-image-compress.php:683-687` | WP-cron fires a hook with no handler every hour / 4 hours forever. Harmless but wasteful, and identical to the "ghost cron" class the comment says it exists to prevent. |
| 1.3 | **Overview and Settings claim an author-enumeration block that does not exist.** Module 11 only removes two REST endpoints. Nothing handles `?author=N` → `/author/name/` redirects, which is the classic enumeration path. | `01-utilities-admin.php:128`, `02-settings.php:73`, `11-rest-enum-block.php` | A security claim in the admin UI that isn't true. Either implement it (a ~6-line `template_redirect` check on `is_author()` + `author` query var) or correct the text. |
| 1.4 | **Both static Event JSON-LD blocks are expired and emit nothing.** Bock Fest ended 2026-04-04, Maifest 2026-05-09. Since then module 29 emits Organization only. The yearly "update the nowdoc and deploy" step is manual with no reminder. | `29-schema-jsonld.php:29-30` | Dead code until someone remembers. See §4.3 for the structural fix. |
| 1.5 | **Curated review with an unparsable date shows the current month.** `strtotime('last spring')` → `false`; `date_i18n('M Y', false)` falls back to *now*. The form hint says "free text". | `33-reviews.php:142-143`, `:311` | Cosmetic, but wrong. Validate at save time (reject if `strtotime()` is false) or store the parsed timestamp. |
| 1.6 | **Short-link collision check misses CPT archives and rewrite endpoints.** It checks `url_to_postid()` and `get_page_by_path(page|post)`. A slug like `events`, `internal-calendar`, or any `gasf_event` slug passes the check and then shadows that URL at `template_redirect` priority 2. | `32-shortlinks.php:76-79` | The whole point of the check (per its own comment) is to refuse silent shadowing. |
| 1.7 | **Version constant is stale and unused.** Header says 2.6.0; `GASF_MEC_VERSION` is 1.19.0 and `GASF_SITE_VERSION` is 1.0.0. Neither is read anywhere. | `gasf-mec-importer.php:5,36`; `00-site-compat.php:10` | Not a runtime bug, but anyone reading the constant gets the wrong answer. Delete both or derive one from the header. |

## 2. Redundant / duplicated code

| # | What | Where | Fix |
|---|---|---|---|
| 2.1 | **Two identical gate helpers.** `gasf_mec_enabled()` and `gasf_site_enabled()` are byte-for-byte the same function. Modules pick one at random: game-tv uses `gasf_mec_enabled` for a `gasf_site_enable_` gate; world-cup uses it for `gasf_mec_enable_wc_schedule`. | `gasf-mec-importer.php:63`, `00-site-compat.php:37` | Keep one. Alias the other for one release, then delete. |
| 2.2 | **18 modules guard with `function_exists('gasf_site_enabled') ? … : true`; 7 don't.** The guard is dead: `00-site-compat.php` always loads first (glob order) and defines it. Worse, the fallback is `true` — if the helper ever *were* missing, every module would silently run with its gate ignored. | all `modules/*.php` top lines | Drop the guards. If you want defence, fail closed (`: false`), but it can't happen. |
| 2.3 | **Three copies of "resolve the Anthropic key".** `gasf_anthropic_key()` (settings), `gasf_aiseo_cfg()` (ai-seo), `gasf_fbs_anthropic_key()` (fb-share) all implement "site-wide key, else legacy aiseo key". | `02-settings.php:29`, `34-ai-seo.php:21-30`, `40-fb-share.php:198` | Modules 34 and 40 call `gasf_anthropic_key()`. |
| 2.4 | **Two copies of the Anthropic HTTP call.** `gasf_aiseo_call()` and the inline `wp_remote_post` in `gasf_fbs_ai_title()` build the same request. Model id `claude-haiku-4-5-20251001` is hard-coded in fb-share and duplicated three times in ai-seo. | `34-ai-seo.php:35`, `40-fb-share.php:213-230` | One `gasf_claude_complete($prompt, $max_tokens, $model = null)` in the main file; one model option. See §6 on the model id itself. |
| 2.5 | **Three different lock implementations in one repo.** imgc: token in option + heartbeat (`38:573`). fb-share: timestamp + raw `DELETE … WHERE option_value = %s` (`40:349-361`). The imgc comment says "two locks in one codebase that behave differently is one of them being wrong" — and then there are three. | `38-image-compress.php:573`, `40-fb-share.php:343-361` | One `gasf_util_lock($name, $ttl)` / `gasf_util_unlock()` pair in the main file. |
| 2.6 | **Double truncation of meta descriptions.** `gasf_seo_excerpt()` cuts to 157 + `…`, then `gasf_seo_desc()` runs the result through `gasf_seo_clip(155)`, which cuts again on a word boundary. The first cut is wasted. | `30-seo.php:354`, `:313` | Return the untruncated excerpt; let `gasf_seo_clip()` be the single enforcement point it says it is. |
| 2.7 | **Sort twice.** World Cup schedule orders in SQL by `_gasf_start_ts`, then `usort()`s by the same key. | `world-cup-schedule.php:34,45` | Drop one. |
| 2.8 | **Yoast migration code still shipping.** `gasf_seo_import_from_yoast()`, the `_yoast_*` fallbacks in `gasf_seo_meta()`, and the "Yoast migration — completed ✓" panel. The UI itself says it already ran and re-running is unnecessary. | `30-seo.php:336-346, 452-480, 540-548` | One `wp post meta` sweep to confirm nothing still relies on `_yoast_*`, then delete. |
| 2.9 | **Shortlinks store a `base` per link that nothing reads.** The redirect ignores it; the copy buttons offer every base anyway. The doc panel admits the dropdown is "display-only". | `32-shortlinks.php:72, 88, 145, 171` | Remove the per-link Base dropdown and the stored field. Keep the Domains list (it drives the copy buttons). |
| 2.10 | `wp_reset_postdata()` after a loop that never called `the_post()`. | `41-recent-posts.php:55` | Delete the line. |
| 2.11 | `require_once wp-admin/includes/file.php` to get `WP_Filesystem`, which is then never used (`file_put_contents` is). | `28-instagram.php:125` | Delete the line. |

## 3. Admin UI: menus that make no sense, unnecessary steps

| # | What | Where |
|---|---|---|
| 3.1 | **The Overview tab is six releases behind.** It links 9 tabs; 14 exist. Missing entirely: Game TV, FB → Blog, Images, PDF → PNG, Google Hours, and the `[gasf_recent_posts]` shortcode. It also lists "misc content 301s" which is no module. It's hand-written prose that nobody updates. | `01-utilities-admin.php:95-131` |
| 3.2 | **Three sources of truth for "what's in this plugin"** — the Overview prose, `gasf_settings_registry()`, and the `gasf_utilities_add_tab()` calls — and they disagree (1.1, 3.1). The registry already has label, description, group, and tab for each gate. The Overview should be *generated* from it, and the registry should be the only place a new module is announced. | `01-utilities-admin.php`, `02-settings.php` |
| 3.3 | **Tab order is arbitrary.** Priorities: Game TV sits at 30 next to SEO (30); Images and FB → Blog tie at 63. The Settings registry groups modules sensibly ("Search & links", "Social & reputation", …) but the tab bar doesn't follow it. With 14 tabs in one `nav-tab-wrapper` the bar wraps to two lines. | all `gasf_utilities_add_tab(…, priority)` calls |
| 3.4 | **A hidden setting with no UI.** FB → Blog has `gasf_fbs_skip_featured`, set only via WP-CLI, documented only in a trailing code comment. The settings form for that module has five other toggles — this belongs there. | `40-fb-share.php:311` |
| 3.5 | **Redirects: the form offers 301/302/410 but the handler also accepts 307.** Either offer it or stop accepting it. | `31-redirects.php:201` vs `:144` |
| 3.6 | **SEO settings has a `title_term` template with no form field.** It can only ever be the default. | `30-seo.php:35, 131` vs the form at `:512-519` |
| 3.7 | **SEO meta map carries `canonical` and `og_image` per-page fields that the editor box never shows.** They only get populated by the (retired) Yoast import. Either add the two fields to the "SEO (GASF)" box or drop them. | `30-seo.php:338-339` vs `gasf_seo_box()` |
| 3.8 | **Redirects "dismiss" gives no feedback**; every other action prints a notice. | `31-redirects.php:169-171` |
| 3.9 | **Image Compressor: cron is "opt-in" per the module, but the Settings-tab description says "On-demand + optional 4-hourly cron" while the module header says "off by default — enable in settings". Fine — but the gate toggle on the Settings tab and the cron checkbox on the Images tab are two switches for the same job, on two pages.** Minor; mention only because a non-developer will toggle the wrong one. | `02-settings.php:78`, `38-image-compress.php:779-785` |
| 3.10 | **Parking: a page that passes no `map=` silently shows the 2026 Sausage Fest map.** Documented as intentional for five existing pages, but it's a footgun for every future event page. | `gas-parking.php:43` |

## 4. Structural / design smells

| # | What | Where | Suggestion |
|---|---|---|---|
| 4.1 | **Config and runtime state in one option.** `gasf_fbshare` holds settings *and* `last` + a 20-row `log`; `gasf_fb_health` holds credentials *and* `last`/`alert`/`warn_key`. Every scan rewrites the credentials row; every settings save rewrites the log. | `40-fb-share.php:30-41`, `35-fb-token-health.php:32-44` | Split state into `gasf_fbshare_state` / `gasf_fb_health_state`. |
| 4.2 | **Load order is load-bearing but encoded only in filenames.** Module 15 needs `gasf_buli_bayern_data()` from 14; 34 needs `gasf_seo_clip()` from 30 (and guards with `function_exists`); two files share prefix `38-`; two have no prefix and load last. Nothing documents that renaming a file can break another. | `gasf-mec-importer.php:84-87` | Either make shared helpers live in the main file (they're 3 functions) or add a one-line rule to the README: "prefix is load order; shared helpers go in the lowest-numbered consumer." |
| 4.3 | **Static festival JSON-LD is a yearly manual chore** (1.4). GASF-Events already emits Event schema for `gasf_event` posts (per the ai-seo doc panel). The Bockfest/Maifest *pages* could emit the schema of their next matching `gasf_event` post instead of a hand-maintained nowdoc. | `29-schema-jsonld.php` | Build the two blocks from the event post at render time, or retire them if GASF-Events' markup is enough. |
| 4.4 | **Getter with a side effect.** `gasf_gametv_tiles()` writes the seed tiles to the DB on first read — including from the public REST route. | `42-game-tv.php:49-74` | Seed on activation / first admin visit; return defaults without writing. |
| 4.5 | **`{$wpdb->prefix}posts` instead of `$wpdb->posts`.** Works on single-site; wrong idiom and breaks on multisite. | `world-cup-schedule.php:30`, `42-game-tv.php:163` | Use `$wpdb->posts` / `$wpdb->postmeta`. |
| 4.6 | **Magic Facebook page id in two places.** `'156837460875'` is the default *and* the fallback in the save handler. | `35-fb-token-health.php:37, 239` | One constant. |
| 4.7 | **`define()` without a `defined()` guard in some modules, with it in others.** Harmless under `require_once`, but inconsistent. | `28:28-29`, `38-image-compress:32-33`, `39:25-27` vs `27:33`, `35:30-31` | Pick one style. |
| 4.8 | **2-second polling timer in the post editor.** The SEO box re-runs its keyphrase analysis every 2 s forever, on top of `input` events, on every post/page/event edit screen. | `30-seo.php:420` | Drop `setInterval`; hook TinyMCE/Gutenberg change events, or debounce. |
| 4.9 | Operator-precedence line that works by accident: `if ( $src && 'photo' === $type || $src && 'album' === $type )`. | `40-fb-share.php:154` | `if ( $src && in_array( $type, ['photo','album'], true ) )`. |

## 5. Stale documentation and comments

| # | What | Where |
|---|---|---|
| 5.1 | Plugin header advertises "the shared-mailbox email CRM" — that moved to GASF-CRM in v2.0.0. | `gasf-mec-importer.php:4` |
| 5.2 | Site-compat header lists a "print button" module that doesn't exist. | `00-site-compat.php:4` |
| 5.3 | README's feature list omits Game TV, Google Hours, PDF→PNG, Image Compressor, FB→Blog, Recent posts, Security headers. | `README.md:3-8` |
| 5.4 | `.gitignore` still ignores `/sweep-test.php` — MEC-era scaffolding removed in v1.3.0. | `.gitignore:2` |
| 5.5 | Instagram `gasf_ig_prune_cache()` docblock still describes the md5-filename re-download problem that the media-id keying (just above it) fixed. | `28-instagram.php:134-141` |
| 5.6 | Misplaced docblocks: "Upcoming published gasf_event posts…" sits above `gasf_gametv_resolve()`, not `gasf_gametv_upcoming()`; "Process up to settings[batch] attachments…" sits above `gasf_imgc_lock()`, not `gasf_imgc_run_batch()`. | `42-game-tv.php:137-145`, `38-image-compress.php:551-566` |
| 5.7 | Settings registry comment says image-compress "reconciles its own cron and isn't listed here" — only true while its gate is on (1.2). | `02-settings.php:206-207` |
| 5.8 | `deploy/mu-plugins-loader.php` is still named "GASF MEC Importer Fixes (loader)" and says "Replaces Code Snippets #17-#21". The filename can't change (documented); the header text can. | `deploy/mu-plugins-loader.php:3-4` |

## 6. The Claude model id (verify before changing)

Both AI call sites use `claude-haiku-4-5-20251001`. Per Anthropic's current
model table (checked 2026-10-28): the current Haiku is `claude-haiku-5-5` at
$0.10 / $0.50 per MTok, versus $1 / $5 for Haiku 4.5 — roughly 10× cheaper for
this workload. Two things to know before switching:

- Haiku 5.5 has adaptive thinking **on by default** when `thinking` is omitted.
  For a 150-character meta description that's wasted tokens; add
  `"output_config": {"effort": "low"}` to the request body.
- Anthropic's migration notes say some Haiku 4.5 request shapes break on 5.5.
  Test with the "Test (sample)" button on the Event SEO tab before the cron
  picks it up.

Dated ids like `…-20251001` are legacy; current ids are undated. This is a
tradeoff for you to decide, not something to change silently.

## 7. Things that are fine (checked, no action)

- Nonce + capability checks on every admin action; `wp_redirect` (not `wp_safe_redirect`) in redirects/shortlinks is deliberate and explained.
- Public REST route `/gasf-util/v1/pinned-games` is read-only and non-sensitive.
- Instagram load-more AJAX serves cache only (the DoS comment is right).
- The updater's fail-closed source selection + snapshot/restore is sound.
- Security headers avoid double-CSP correctly.
- 404 log and redirect hit counters are throttled correctly against scanners.

## 8. Recommended order of work

1. **Bugs 1.1, 1.2, 1.3** — small, each under 20 lines, and the first two are
   exactly the class of silent failure the code comments say the plugin exists
   to prevent.
2. **Consolidate (2.1–2.5)** — one gate helper, one key resolver, one Claude
   call, one lock. Removes ~120 lines and the drift surface.
3. **Generate the Overview from the registry (3.1–3.3)** and add `ghours` /
   `pdf2png` to it. After this there is one list to maintain.
4. **Retire Yoast code (2.8)** after a one-time DB check.
5. **Small UI fixes (3.4–3.8, 1.5, 1.6)**.
6. **Decide** on 1.4/4.3 (festival schema), 3.10 (parking default map), §6
   (model id).
7. **Docs sweep (§5)** in the same PR as whatever touches each file.
