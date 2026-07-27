<?php
/**
 * Email CRM — modules/42-email-crm.php
 *
 * A shared-inbox CRM for info@germantampabay.com. Approved volunteers sign in
 * at /email with Google or Microsoft, see inbound mail, and reply from a web
 * form with a Claude Haiku draft as a starting point.
 *
 * Design contract: docs/EMAIL-CRM-SPEC.md
 *
 * Mail access is Microsoft Graph APP-ONLY against a SHARED mailbox — there is
 * no per-user delegation, because the volunteers' Google/Microsoft identities
 * have no relationship to the M365 tenant. The Graph app is restricted to that
 * one mailbox by an Exchange Application Access Policy scoped to the
 * gasf-crm-scope@germantampabay.com mail-enabled security group. That policy is
 * the security boundary; without it the app registration can read every mailbox
 * in the tenant. Verify with Test-ApplicationAccessPolicy after any app change.
 *
 * This file is only the loader — gate, includes, rewrite rules, cron wiring and
 * schema upgrades. Everything else lives in modules/email-crm/*.php, which the
 * plugin's own modules/*.php glob deliberately does NOT pick up (it is not
 * recursive), so load order stays under our control here.
 *
 * Gate: gasf_site_enable_emailcrm (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'gasf_site_enabled' ) ? gasf_site_enabled( 'gasf_site_enable_emailcrm' ) : true ) {

	define( 'GASF_CRM_DIR', __DIR__ . '/email-crm' );

	// Schema/rewrite version. Bump when tables or rewrite rules change — the
	// upgrade check below runs dbDelta and flushes rules on any change. This
	// plugin runs as an mu-plugin on the main site, where activation hooks
	// never fire, so a version-compare on every load is the only reliable hook.
	define( 'GASF_CRM_SCHEMA', '1.4.0' );

	/**
	 * How far ahead to start warning that the Graph client secret is running
	 * out. Two months is enough to notice, find the time, and still have a
	 * cushion — and short enough that the warning is not background noise for a
	 * year and a half.
	 */
	define( 'GASF_CRM_SECRET_WARN_DAYS', 61 );

	require_once GASF_CRM_DIR . '/db.php';
	require_once GASF_CRM_DIR . '/graph.php';
	require_once GASF_CRM_DIR . '/attachments.php';
	require_once GASF_CRM_DIR . '/sync.php';
	require_once GASF_CRM_DIR . '/health.php';
	require_once GASF_CRM_DIR . '/wpmail.php';
	require_once GASF_CRM_DIR . '/auth.php';
	require_once GASF_CRM_DIR . '/ai.php';
	require_once GASF_CRM_DIR . '/notify.php';
	require_once GASF_CRM_DIR . '/rest.php';
	require_once GASF_CRM_DIR . '/ui.php';
	require_once GASF_CRM_DIR . '/admin.php';

	/**
	 * Module config. The client SECRET lives here too — this file is in a public
	 * repo, so it is read from wp_options at runtime and never written to disk
	 * by anything in this tree. Tenant/client IDs are not secret; the secret is.
	 *
	 * autoload is off (see gasf_crm_save_cfg) so the secret is not loaded into
	 * every single page request just to render the front page.
	 */
	/**
	 * The mailboxes this CRM watches, keyed by stream.
	 *
	 * A "stream" is which inbox a thread belongs to. It exists because access is
	 * per-stream: the photo team can be granted photos@ without seeing general
	 * enquiries. That boundary is enforced HERE, in application code — the CRM
	 * is app-only, volunteers never authenticate to Microsoft, so no Exchange
	 * mailbox permission applies to them and none can be relied on.
	 *
	 * 'general' must keep the key it has: existing threads were written before
	 * streams existed and are backfilled to it.
	 */
	function gasf_crm_streams() {
		$cfg = gasf_crm_cfg();
		return (array) apply_filters( 'gasf_crm_streams', array(
			'general' => array(
				'label'   => __( 'General', 'gasf' ),
				'mailbox' => $cfg['mailbox'],
			),
			'photos'  => array(
				'label'   => __( 'Photo submissions', 'gasf' ),
				'mailbox' => $cfg['mailbox_photos'],
			),
		) );
	}

	/** Streams with a mailbox actually configured. */
	function gasf_crm_active_streams() {
		return array_filter( gasf_crm_streams(), static function ( $s ) {
			return ! empty( $s['mailbox'] );
		} );
	}

	/** Mailbox address for a stream key, or '' if unknown/unconfigured. */
	function gasf_crm_stream_mailbox( $stream ) {
		$s = gasf_crm_streams();
		return isset( $s[ $stream ] ) ? (string) $s[ $stream ]['mailbox'] : '';
	}

	/** Human label for a stream key. */
	function gasf_crm_stream_label( $stream ) {
		$s = gasf_crm_streams();
		return isset( $s[ $stream ] ) ? (string) $s[ $stream ]['label'] : $stream;
	}

	function gasf_crm_cfg() {
		return wp_parse_args( (array) get_option( 'gasf_crm_config', array() ), array(
			'mailbox'        => 'info@germantampabay.com',
			// Second watched mailbox. Blank disables the stream entirely rather
			// than half-enabling it.
			'mailbox_photos' => '',
			'tenant_id'      => '',
			'client_id'      => '',
			'client_secret'  => '',
			'google_id'      => '',
			'google_secret'  => '',
			'ms_id'          => '',
			'ms_secret'      => '',
			'signature_org'  => 'German-American Society Friendship of Pinellas County',
			// One-click forward destination. Configurable rather than hardcoded
			// so a change of address is a settings edit, not a deploy. Blank
			// hides the button entirely.
			'board_address'  => 'board@germantampabay.com',
			'notify_channel' => 'email',
			'notify_extra'   => '',
			// Per-mailbox sync cursors, keyed by stream. A single shared cursor
			// would let a fast-moving mailbox drag the other one's window past
			// unread mail. 'last_sync' below stays for the general stream so
			// existing installs keep their position across this upgrade.
			'last_sync_by'   => array(),
			// Send ALL WordPress mail through Graph, not just this module's.
			// Nothing wp_mail sends from this server reaches anyone otherwise —
			// the domain's own SPF and DMARC records see to that.
			'route_wp_mail'  => 1,
			// Y-m-d the Graph client secret stops working. Entra will not tell us
			// this after the fact, so it is recorded by hand at creation time.
			// Empty means nobody wrote it down, which the admin tab nags about —
			// an unknown expiry is only marginally better than a passed one,
			// because both end in mail silently ceasing to arrive.
			'secret_expiry'  => '',
			'last_sync'      => 0,
		) );
	}

	function gasf_crm_save_cfg( array $cfg ) {
		update_option( 'gasf_crm_config', $cfg, false ); // autoload off — holds secrets
	}

	/** True once the Graph side is configured enough to talk to the mailbox. */
	function gasf_crm_ready() {
		$c = gasf_crm_cfg();
		return '' !== $c['tenant_id'] && '' !== $c['client_id'] && '' !== $c['client_secret'];
	}

	/* ---------------------------------------------------------------------
	 * Rewrite rules — /email and its OAuth callbacks.
	 *
	 * A virtual route, not a WordPress page: the spec calls for an unlinked
	 * surface, and a real page would show up in menus, sitemaps, search and the
	 * Pages list where someone would eventually "tidy" it away.
	 * ------------------------------------------------------------------- */
	add_action( 'init', function () {
		add_rewrite_rule( '^email/?$', 'index.php?gasf_crm=app', 'top' );
		add_rewrite_rule( '^email/auth/([a-z]+)/?$', 'index.php?gasf_crm=start&gasf_crm_provider=$matches[1]', 'top' );
		add_rewrite_rule( '^email/auth/([a-z]+)/callback/?$', 'index.php?gasf_crm=callback&gasf_crm_provider=$matches[1]', 'top' );
		add_rewrite_rule( '^email/logout/?$', 'index.php?gasf_crm=logout', 'top' );
	} );

	add_filter( 'query_vars', function ( $vars ) {
		$vars[] = 'gasf_crm';
		$vars[] = 'gasf_crm_provider';
		return $vars;
	} );

	/**
	 * Schema + rewrite upgrade. Cheap option read on every load; the expensive
	 * work only runs when GASF_CRM_SCHEMA changes.
	 */
	add_action( 'init', function () {
		if ( get_option( 'gasf_crm_schema' ) === GASF_CRM_SCHEMA ) { return; }
		gasf_crm_install_tables();

		// Threads written before streams existed belong to the general inbox —
		// it was the only one. dbDelta's column default covers new rows; this
		// covers the ones already there, and is safe to re-run.
		global $wpdb;
		$wpdb->query( "UPDATE " . gasf_crm_table( 'threads' ) . " SET stream = 'general' WHERE stream = '' OR stream IS NULL" ); // phpcs:ignore

		flush_rewrite_rules( false );
		update_option( 'gasf_crm_schema', GASF_CRM_SCHEMA, false );
		gasf_mec_log( 'CRM: schema/rewrites upgraded to ' . GASF_CRM_SCHEMA );
	}, 20 );

	/* ---------------------------------------------------------------------
	 * Sync cron — hourly, per spec (~4 emails/week; Graph change
	 * notifications would need subscription renewal inside a 3-day window,
	 * which is not worth building at this volume).
	 *
	 * WP-Cron only fires on page traffic, and a club site can sit idle for
	 * hours. A real system cron hitting `wp gasf-crm sync` is the supported
	 * path; this scheduled event is the fallback so the thing still works if
	 * nobody sets that up.
	 * ------------------------------------------------------------------- */
	add_action( 'init', function () {
		if ( ! wp_next_scheduled( 'gasf_crm_sync_event' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'gasf_crm_sync_event' );
		}
	} );
	add_action( 'gasf_crm_sync_event', 'gasf_crm_sync' );

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( 'gasf-crm sync', function () {
			$r = gasf_crm_sync();
			WP_CLI::success( sprintf(
				'%d new message(s), %d reopened, %d queued, %d announced.',
				$r['new'], $r['reopened'], $r['queued'], $r['notified']
			) );
		} );
	}
}
