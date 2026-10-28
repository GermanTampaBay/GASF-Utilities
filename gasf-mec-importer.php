<?php
/**
 * Plugin Name: GASF Utilities
 * Description: All custom germantampabay.com functionality in one update-safe plugin: SEO + AI event descriptions, short links, redirects, Instagram feed, reviews wall, Facebook token watchdog, the shared-mailbox email CRM, performance and hardening tweaks, and more. Each module is individually gated — see the GASF Utilities → Settings tab. (Home-page heroes moved to the GASF-Events plugin.)
 * Version:     2.7.0
 * Author:      GASF
 * License:     GPL-2.0-or-later
 * Update URI:  https://github.com/GermanTampaBay/GASF-Utilities
 *
 * Repo: https://github.com/GermanTampaBay/GASF-Utilities
 *
 * ---------------------------------------------------------------------------
 * SECURITY: This file is tracked in a PUBLIC repo. NEVER hardcode secrets.
 * Every token/key lives in wp_options and is read at runtime.
 * ---------------------------------------------------------------------------
 *
 * This file is named gasf-mec-importer.php for historical reasons — the plugin
 * began life as the MEC Advanced Importer fixes. Renaming it would break the
 * mu-plugin shim on the main site (wp-content/mu-plugins/gasf-mec-importer.php)
 * and the registered plugin path on the Krampus install, so the name stays.
 * The MEC-era modules themselves (importer cron/defaults/window/recurrence/
 * dedup-sweep/single-template, FB refresh, URL Shortify branding) were removed
 * in v1.3.0 — MEC is retired on both sites; see git history for the code.
 *
 * All functionality lives in modules/*.php, loaded by the glob at the bottom.
 * Every module self-gates on a wp_option (gasf_site_enable_* /
 * gasf_mec_enable_*, default ON, '0' = off) via gasf_site_enabled() — toggle
 * them in wp-admin > GASF Utilities > Settings (modules/02-settings.php), or
 * via `wp option update <gate> 0`.
 *
 * Shared helpers defined here, before any module loads: gasf_site_enabled(),
 * gasf_mec_log(), and the gasf_util_lock() family. The Claude client
 * (gasf_anthropic_key / gasf_anthropic_model / gasf_claude_text) is
 * modules/03-claude.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GASF_MEC_VERSION', '2.7.0' );

/**
 * owner/repo on GitHub, for the auto-updater below.
 *
 * A constant because the same slug appeared in five places and the repo moved
 * from a personal account to the GermanTampaBay organisation in July 2026 —
 * which silently broke nothing at the time only because it was caught first.
 * The failure mode is nasty: the version check stores a '0' sentinel when it
 * cannot reach GitHub, so a wrong slug means updates simply stop appearing,
 * with no error anywhere.
 *
 * The `Update URI:` header above must stay a literal — WordPress parses the
 * file header before any of this executes — so that one still needs editing by
 * hand if the repo ever moves again. Its HOSTNAME also selects the
 * update_plugins_{$hostname} filter used below, so it must remain github.com.
 */
define( 'GASF_UTIL_REPO', 'GermanTampaBay/GASF-Utilities' );

// Log lives OUTSIDE the web root (parent of ABSPATH), not web-fetchable.
// Falls back silently if unwritable (logging is best-effort).
define( 'GASF_MEC_LOG', dirname( untrailingslashit( ABSPATH ) ) . '/gasf-mec-importer.log' );
define( 'GASF_MEC_LOG_MAX', 1048576 ); // 1 MB, then rotate to .1

/**
 * Feature gate: true unless the option is explicitly '0'/0/false.
 *
 * Guarded because the retired gasf-site.php loader defined the same name;
 * if a stray copy is ever activated, its definition wins and nothing fatals.
 */
if ( ! function_exists( 'gasf_site_enabled' ) ) {
	function gasf_site_enabled( $option, $default = '1' ) {
		$v = get_option( $option, $default );
		return ! ( $v === '0' || $v === 0 || $v === false || $v === 'false' );
	}
}

/**
 * @deprecated 2.7.0 — identical to gasf_site_enabled(); kept one release for
 * any caller outside this repo (GASF-Events / GASF-CRM). Grep those, then
 * delete this.
 */
function gasf_mec_enabled( $option, $default = '1' ) {
	return gasf_site_enabled( $option, $default );
}

/**
 * Best-effort, size-capped log line. Server-local time.
 */
function gasf_mec_log( $msg ) {
	$f = GASF_MEC_LOG;
	if ( @file_exists( $f ) && @filesize( $f ) > GASF_MEC_LOG_MAX ) {
		@rename( $f, $f . '.1' );
	}
	@file_put_contents( $f, '[' . date( 'Y-m-d H:i:s' ) . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX );
}

/* =========================================================================
 * Shared batch lock.
 *
 * One implementation for every "don't run this twice at once" in the plugin
 * (the image compressor's batch, the FB→Blog scan, …). It replaced two
 * hand-rolled locks that each claimed add_option() was atomic. It is not:
 * add_option() is INSERT … ON DUPLICATE KEY UPDATE, so two callers racing
 * through it BOTH return true and the second silently overwrites the
 * first's row. Core's own WP_Upgrader::create_lock() uses a raw INSERT
 * IGNORE for exactly this reason, and so does this.
 *
 *   $token = gasf_util_lock( 'gasf_imgc_lock', 10 * MINUTE_IN_SECONDS );
 *   if ( ! $token ) { return; }          // somebody else holds it
 *   … gasf_util_lock_touch( 'gasf_imgc_lock', $token ); per unit of work …
 *   gasf_util_unlock( 'gasf_imgc_lock', $token );
 *
 * A lock whose holder stopped touching it for longer than $stale_after is
 * broken with a compare-and-delete, so two breakers cannot each clear the
 * other's fresh lock. Touch and unlock are compare-and-swap on the token:
 * a caller only ever releases its own lock, never one it broke and lost.
 * Every query bypasses the option cache — the row is written and read raw
 * so a stale cached copy can never report a lock that isn't there.
 * ========================================================================= */

/** Serialized row for a lock — the exact bytes stored, used in every compare. */
function gasf_util_lock_row( $token, $at ) {
	return serialize( array( 'token' => (string) $token, 'at' => (int) $at ) );
}

/** Raw read of the lock row, as the stored string, or '' when absent. */
function gasf_util_lock_read( $name ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
}

/**
 * Take the lock. Returns the token on success, '' when another run holds it.
 *
 * @param string $name        Option name for the lock row (one per job).
 * @param int    $stale_after Seconds without a touch before the lock is broken.
 */
function gasf_util_lock( $name, $stale_after = 600 ) {
	global $wpdb;
	$token = wp_generate_password( 12, false );
	$row   = gasf_util_lock_row( $token, time() );
	$try   = function () use ( $wpdb, $name, $row ) {
		$n = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $row ) );
		wp_cache_delete( $name, 'options' );
		return 1 === (int) $n;
	};
	if ( $try() ) { return $token; }

	$held = gasf_util_lock_read( $name );
	$data = maybe_unserialize( $held );
	// Pre-2.7 FB→Blog locks stored a bare timestamp string; read either shape.
	$at = is_array( $data ) ? (int) ( $data['at'] ?? 0 ) : (int) $data;
	if ( $at && ( time() - $at ) < max( 1, (int) $stale_after ) ) { return ''; }

	gasf_mec_log( "lock {$name}: breaking a stale lock (untouched for " . ( $at ? time() - $at : '?' ) . 's)' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $held ) );
	return $try() ? $token : '';
}

/** Heartbeat: refresh 'at' on OUR lock so a long, live run never looks stale. */
function gasf_util_lock_touch( $name, $token ) {
	global $wpdb;
	$cur = gasf_util_lock_read( $name );
	$d   = maybe_unserialize( $cur );
	if ( ! is_array( $d ) || ( $d['token'] ?? '' ) !== (string) $token ) { return false; }
	return (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", gasf_util_lock_row( $token, time() ), $name, $cur ) );
}

/** Release, but only our own — a lock we broke as stale may since be somebody's. */
function gasf_util_unlock( $name, $token ) {
	global $wpdb;
	$cur = gasf_util_lock_read( $name );
	$d   = maybe_unserialize( $cur );
	if ( ! is_array( $d ) || ( $d['token'] ?? '' ) !== (string) $token ) { return false; }
	$n = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $cur ) );
	wp_cache_delete( $name, 'options' );
	return (bool) $n;
}

/* === MODULE LOADER: each file in modules/ self-gates via its gate option === */
foreach ( glob( __DIR__ . "/modules/*.php" ) as $gasf_mec_mod ) {
	require_once $gasf_mec_mod;
}
unset( $gasf_mec_mod );

/* =========================================================================
 * GitHub auto-updates (WP 5.8+ `Update URI` mechanism)
 *
 * Only meaningful where this runs as a REGULAR plugin (e.g. the Krampus
 * site). On the main site it loads as an mu-plugin via the shim, is never in
 * the plugins list, and these hooks simply never match. Public repo -> no
 * credentials. Bump the Version header above for installs to see updates.
 * ========================================================================= */
add_filter( 'update_plugins_github.com', function ( $update, $plugin_data, $plugin_file ) {
	if ( basename( $plugin_file ) !== basename( __FILE__ ) ) { return $update; }
	$ver = get_transient( 'gasf_util_update_check' );
	if ( ! is_string( $ver ) ) {
		$ver  = '';
		$resp = wp_remote_get( 'https://raw.githubusercontent.com/' . GASF_UTIL_REPO . '/main/gasf-mec-importer.php', array( 'timeout' => 15 ) );
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp )
			&& preg_match( '/^\s*\*?\s*Version:\s*([0-9][0-9a-z.\-]*)/mi', (string) wp_remote_retrieve_body( $resp ), $m ) ) {
			$ver = trim( $m[1] );
		}
		// '0' = failure sentinel: '' was previously stored on failure but the
		// read above treated '' as a cache miss, so every update-check cycle
		// re-hit GitHub until one succeeded — the old transient was a no-op in
		// the failure path. TTL is 15 min (was 6 h, which made every fresh push
		// invisible to `wp plugin update` for hours).
		set_transient( 'gasf_util_update_check', '' === $ver ? '0' : $ver, 15 * MINUTE_IN_SECONDS );
	}
	if ( '' === $ver || '0' === $ver || version_compare( $ver, (string) $plugin_data['Version'], '<=' ) ) { return $update; }
	return array(
		'id'      => 'https://github.com/' . GASF_UTIL_REPO,
		'slug'    => dirname( $plugin_file ),
		'plugin'  => $plugin_file,
		'version' => $ver,
		'url'     => 'https://github.com/' . GASF_UTIL_REPO,
		'package' => 'https://github.com/' . GASF_UTIL_REPO . '/archive/refs/heads/main.zip',
	);
}, 10, 3 );

// GitHub zipballs extract as GASF-Utilities-main/ — rename to the installed
// folder. Hardened to fail CLOSED: if it can't produce a correctly-named folder
// that actually contains this main file, it returns a WP_Error, which aborts the
// upgrade in install_package() BEFORE WordPress deletes the installed version —
// so a flaky rename can never leave an empty, still-active plugin folder.
add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $hook_extra ) {
	if ( is_wp_error( $source ) || empty( $hook_extra['plugin'] ) || basename( (string) $hook_extra['plugin'] ) !== basename( __FILE__ ) ) { return $source; }
	global $wp_filesystem;
	if ( ! $wp_filesystem ) { return $source; }
	$main = basename( (string) $hook_extra['plugin'] );                                    // gasf-mec-importer.php
	$want = trailingslashit( $remote_source ) . dirname( (string) $hook_extra['plugin'] ) . '/'; // …/GASF-Utilities/
	if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) {
		return $wp_filesystem->exists( $want . $main ) ? $source : new WP_Error( 'gasf_pkg_incomplete', 'Update package missing ' . $main . '; keeping the installed version.' );
	}
	if ( $wp_filesystem->exists( $want ) ) { $wp_filesystem->delete( $want, true ); }
	if ( $wp_filesystem->move( $source, $want, true ) && $wp_filesystem->exists( $want . $main ) ) { return $want; }
	return new WP_Error( 'gasf_pkg_move', 'Could not prepare the GASF Utilities update (folder rename failed); keeping the installed version.' );
}, 10, 4 );

/* -------------------------------------------------------------------------
 * 2026-07-24 incident hardening (ported from GASF-Events 2d058a4).
 *
 * Two concurrent updates of a plugin (WP takes NO lock for manual/CLI plugin
 * updates) can interleave clear_destination/copy_dir and leave the plugin
 * folder EMPTY while both runs report success — that's exactly what wiped
 * GASF-Events on Krampus. The fail-closed guard above can't catch it: it
 * protects the staging step, not the destination step. Two more layers:
 *   1. upgrader_pre_install       — serialize via a WP_Upgrader lock (a
 *      second concurrent run aborts BEFORE the destination is cleared) and
 *      snapshot the installed plugin dir.
 *   2. upgrader_install_package_result — if the result is an error or this
 *      main file is missing afterwards, restore the snapshot. Any failure
 *      mode heals back to the prior version. Then release the lock.
 * ------------------------------------------------------------------------- */

/** Plain-PHP recursive copy — deliberately independent of WP_Filesystem. */
function gasf_util_copy_tree( $from, $to ) {
	if ( ! is_dir( $from ) ) { return false; }
	if ( ! is_dir( $to ) && ! mkdir( $to, 0755, true ) ) { return false; }
	$ok = true;
	foreach ( new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	) as $item ) {
		$dest = $to . '/' . substr( (string) $item, strlen( $from ) + 1 );
		if ( $item->isDir() ) {
			$ok = ( is_dir( $dest ) || mkdir( $dest, 0755, true ) ) && $ok;
		} else {
			$ok = copy( (string) $item, $dest ) && $ok;
		}
	}
	return $ok;
}

function gasf_util_delete_tree( $dir ) {
	if ( ! is_dir( $dir ) ) { return; }
	foreach ( new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	) as $item ) {
		$item->isDir() ? rmdir( (string) $item ) : unlink( (string) $item );
	}
	rmdir( $dir );
}

function gasf_util_backup_dir() {
	return WP_CONTENT_DIR . '/upgrade/gasf-utilities-preupdate';
}

// Abort a second concurrent run before it can clear the destination; snapshot the install.
add_filter( 'upgrader_pre_install', function ( $response, $hook_extra ) {
	$hook_extra = (array) $hook_extra;
	if ( is_wp_error( $response ) || empty( $hook_extra['plugin'] ) || basename( (string) $hook_extra['plugin'] ) !== basename( __FILE__ ) ) { return $response; }
	if ( class_exists( 'WP_Upgrader' ) && ! WP_Upgrader::create_lock( 'gasf_util_upgrade', 2 * MINUTE_IN_SECONDS ) ) {
		return new WP_Error( 'gasf_locked', 'Another GASF Utilities update is already running; skipped to avoid a destructive race.' );
	}
	$backup = gasf_util_backup_dir();
	gasf_util_delete_tree( $backup );
	if ( is_dir( __DIR__ ) && file_exists( __FILE__ ) ) {
		gasf_util_copy_tree( __DIR__, $backup );
	}
	return $response;
}, 10, 2 );

// After install: verify the plugin dir is intact; restore the snapshot if not.
add_filter( 'upgrader_install_package_result', function ( $result, $hook_extra ) {
	$hook_extra = (array) $hook_extra;
	if ( empty( $hook_extra['plugin'] ) || basename( (string) $hook_extra['plugin'] ) !== basename( __FILE__ ) ) { return $result; }
	$backup = gasf_util_backup_dir();
	$broken = is_wp_error( $result ) || ! file_exists( __FILE__ );
	if ( $broken && is_dir( $backup ) && file_exists( trailingslashit( $backup ) . basename( __FILE__ ) ) ) {
		gasf_util_copy_tree( $backup, __DIR__ );
		gasf_mec_log( 'updater: install left the plugin broken ('
			. ( is_wp_error( $result ) ? $result->get_error_code() : 'main file missing' )
			. ') — restored the pre-update snapshot.' );
	}
	gasf_util_delete_tree( $backup );
	if ( class_exists( 'WP_Upgrader' ) ) {
		WP_Upgrader::release_lock( 'gasf_util_upgrade' );
	}
	return $result;
}, 10, 2 );
